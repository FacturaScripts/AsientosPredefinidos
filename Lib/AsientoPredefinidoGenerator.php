<?php
/**
 * This file is part of AsientoPredefinido plugin for FacturaScripts
 * Copyright (C) 2021-2026 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\AsientosPredefinidos\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Where;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\CodePatterns;
use FacturaScripts\Dinamic\Model\Asiento;
use FacturaScripts\Dinamic\Model\Subcuenta;
use FacturaScripts\Dinamic\Model\AsientoPredefinido;
use FacturaScripts\Dinamic\Model\AsientoPredefinidoLinea;
use FacturaScripts\Dinamic\Model\AsientoPredefinidoVariable;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * @author Carlos García Gómez            <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez       <contacto@danielfg.es>
 * @author Jeronimo Pedro Sánchez Manzano <socger@gmail.com>
 */
class AsientoPredefinidoGenerator
{
    /**
     * Genera un asiento contable a partir de un asiento predefinido y los valores del formulario.
     * Si algo falla (variables sin valor, subcuenta inexistente o fórmula no evaluable) avisa
     * con un mensaje y devuelve un asiento que no existe en la base de datos.
     *
     * @param AsientoPredefinido $predefinido
     * @param array $form
     *
     * @return Asiento
     */
    public static function generate(AsientoPredefinido $predefinido, array $form): Asiento
    {
        $asiento = new Asiento();

        // Comprobamos las variables
        $variables = $predefinido->getVariables();
        $lines = $predefinido->getLines();
        if (false === static::checkVariables($form, $variables, $lines)) {
            return $asiento; // Devolvemos el asiento incompleto, vacío.
        }

        $database = new DataBase();
        $database->beginTransaction();

        $asiento->idasientopre = $predefinido->id;
        $asiento->idempresa = (int)$form["idempresa"];
        $asiento->setDate($form["fecha"]);
        $concepto = empty($form["concepto"]) ? $predefinido->concepto : $form["concepto"];
        $asiento->concepto = CodePatterns::trans($concepto, $asiento);
        $asiento->canal = $form["canal"] ?? null;
        if (false === $asiento->save()) {
            Tools::log()->warning('no-can-create-accounting-entry');
            $database->rollback();
            return $asiento; // Devolvemos el asiento incompleto, vacío.
        }

        // Recorremos todas las líneas/partidas del asiento predefinido para crearlas en el asiento que estamos creando

        $saldoDebe = 0.0;
        $saldoHaber = 0.0;
        $lineCount = count($lines);
        foreach ($lines as $index => $line) {
            // Creamos la partida
            $newLine = $asiento->getNewLine();
            $newLine->concepto = CodePatterns::trans($line->concepto, $asiento);
            // Partida::getLines() devuelve por orden DESC. Invertimos aquí para
            // conservar en el asiento final el orden visual definido en la plantilla.
            $newLine->orden = $lineCount - $index;
            try {
                $newLine->debe = static::cantidadReplace($line->debe, $variables, $form, $saldoDebe, $saldoHaber);
                $newLine->haber = static::cantidadReplace($line->haber, $variables, $form, $saldoDebe, $saldoHaber);
            } catch (\Throwable $exception) {
                // la fórmula no se puede evaluar: avisamos y cancelamos la generación
                Tools::log()->warning('invalid-formula', [
                    '%formula%' => $line->debe . ' / ' . $line->haber,
                    '%error%' => $exception->getMessage()
                ]);
                $asiento->delete();
                $database->rollback();
                return $asiento;
            }

            // redondeamos
            $decimals = Tools::settings('default', 'decimals', 2);
            $newLine->debe = round($newLine->debe, $decimals);
            $newLine->haber = round($newLine->haber, $decimals);

            // obtenemos la subcuenta
            $subcuenta = static::subcuentaReplace($line->codsubcuenta, $variables, $form, $asiento->codejercicio);
            if (false === $subcuenta->exists()) {
                $asiento->delete();
                $database->rollback();
                return $asiento;
            }

            // establecemos la contrapartida
            if (false === empty($line->codcontrapartida)) {
                $contrapartida = static::subcuentaReplace($line->codcontrapartida, $variables, $form, $asiento->codejercicio);
                if (false === $contrapartida->exists()) {
                    $asiento->delete();
                    $database->rollback();
                    return $asiento;
                }
                $newLine->setCounterpart($contrapartida);
            }

            // asignamos la subcuenta y guardamos la línea
            $newLine->setAccount($subcuenta);
            if (false === $newLine->save()) {
                $asiento->delete();
                $database->rollback();
                return $asiento;
            }

            // Recalculamos saldos de asiento
            $saldoDebe += $newLine->debe;
            $saldoHaber += $newLine->haber;
        }

        // Rellenamos el importe del asiento
        $asiento->importe = $saldoDebe;
        if (false === $asiento->save()) {
            $database->rollback();
            $asiento->delete();
        }

        $database->commit();
        return $asiento;
    }

    /**
     * Calcula el importe de una cantidad (debe o haber) de una línea, sustituyendo
     * las variables por sus valores y evaluando la fórmula resultante.
     *
     * La variable Z (saldo acumulado) se redondea a los decimales configurados y todos los
     * valores se escriben sin notación científica. La sustitución se hace en una sola
     * pasada, para que el valor de una variable nunca se vuelva a sustituir por otra.
     *
     * @param string $cantidad
     * @param AsientoPredefinidoVariable[] $variables
     * @param array $form
     * @param float $saldoDebe
     * @param float $saldoHaber
     *
     * @return float
     * @throws \Throwable si la fórmula resultante no se puede evaluar
     */
    protected static function cantidadReplace(string $cantidad, array $variables, array $form, float $saldoDebe, float $saldoHaber): float
    {
        // reemplazamos las variables por sus valores, en una sola pasada
        $decimals = (int)Tools::settings('default', 'decimals', 2);
        $replacements = ['Z' => static::formatNumber(round($saldoDebe - $saldoHaber, $decimals))];
        foreach ($variables as $var) {
            if ('' === (string)$var->codigo) {
                continue;
            }
            $valorVar = $form['var_' . $var->codigo];
            $replacements[$var->codigo] = is_numeric($valorVar) ? static::formatNumber((float)$valorVar) : (string)$valorVar;
        }
        $valor = strtr($cantidad, $replacements);

        // Si es una fórmula, calculamos
        foreach (['+', '-', '/', '*'] as $operator) {
            if (false !== strpos($valor, $operator)) {
                $expressionLanguage = new ExpressionLanguage();
                return (float)$expressionLanguage->evaluate($valor);
            }
        }

        return (float)$valor;
    }

    /**
     * Convierte un número a texto con punto decimal y sin notación científica
     * (por ejemplo, 5.55E-17 se convierte en "0" y -2.5 se queda en "-2.5").
     *
     * @param float $numero
     *
     * @return string
     */
    protected static function formatNumber(float $numero): string
    {
        $texto = rtrim(rtrim(number_format($numero, 10, '.', ''), '0'), '.');
        return in_array($texto, ['', '-', '-0'], true) ? '0' : $texto;
    }

    /**
     * Comprobamos que todas las variables tengan valor asignado en $form
     *
     * @param array $form
     * @param AsientoPredefinidoVariable[] $variables
     * @param AsientoPredefinidoLinea[] $lines
     *
     * @return bool
     */
    protected static function checkVariables(array $form, array $variables, array $lines): bool
    {
        // ¿Todas las variables tienen valor válido?
        foreach ($variables as $var) {
            $valor = $form['var_' . $var->codigo] ?? '';
            if (false === is_numeric($valor)) {
                Tools::log()->warning('La variable ' . $var->codigo . ' no tiene valor.');
                return false;
            }
        }

        // buscamos variables en las líneas
        foreach ($lines as $linea) {
            $combinado = $linea->codsubcuenta . $linea->debe . $linea->haber;
            $aislado = preg_replace("/[^A-Z]/", "", $combinado);
            for ($i = 0; $i < strlen($aislado); $i++) {
                if ('Z' === $aislado[$i]) {
                    continue;
                }

                $valor = $form['var_' . $aislado[$i]] ?? '';
                if (false === is_numeric($valor)) {
                    Tools::log()->warning('La variable ' . $aislado[$i] . ' no está registrada.');
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param string $codsubcuenta
     * @param AsientoPredefinidoVariable[] $variables
     * @param array $form
     * @param string $codejercicio
     *
     * @return Subcuenta
     */
    protected static function subcuentaReplace(string $codsubcuenta, array $variables, array $form, string $codejercicio): Subcuenta
    {
        // reemplazamos las variables por sus valores
        $search = [];
        $replace = [];
        foreach ($variables as $var) {
            $search[] = $var->codigo;
            $replace[] = $form['var_' . $var->codigo];
        }
        $valor = str_replace($search, $replace, $codsubcuenta);

        // buscamos la subcuenta
        $subcuenta = new Subcuenta();
        $subcuenta->codejercicio = $codejercicio; // necesario para transformCodsubcuenta()
        $valorFinal = $subcuenta->transformCodsubcuenta($valor);
        $where = [
            Where::eq('codejercicio', $codejercicio),
            Where::eq('codsubcuenta', $valorFinal) // transforma el punto en ceros
        ];
        if (false === $subcuenta->loadWhere($where)) {
            Tools::log()->warning('subaccount-not-found', ['%code%' => $valorFinal]);
        }
        return $subcuenta;
    }
}

