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
    /** @var ExpressionLanguage|null se reutiliza para aprovechar su caché de expresiones */
    protected static $expressionLanguage;

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

        $decimals = (int)Tools::settings('default', 'decimals', 2);
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

            // calculamos y redondeamos el debe y el haber
            $formula = $line->debe;
            try {
                $newLine->debe = round(static::cantidadReplace($line->debe, $variables, $form, $saldoDebe, $saldoHaber), $decimals);
                $formula = $line->haber;
                $newLine->haber = round(static::cantidadReplace($line->haber, $variables, $form, $saldoDebe, $saldoHaber), $decimals);
            } catch (\Throwable $exception) {
                // la fórmula no se puede evaluar: avisamos indicando cuál es y cancelamos la generación
                Tools::log()->warning('invalid-formula', [
                    '%formula%' => $formula,
                    '%error%' => $exception->getMessage()
                ]);
                $asiento->delete();
                $database->rollback();
                return $asiento;
            }

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

            // Recalculamos saldos de asiento, redondeados para no acumular errores de coma flotante
            $saldoDebe = round($saldoDebe + $newLine->debe, $decimals);
            $saldoHaber = round($saldoHaber + $newLine->haber, $decimals);
        }

        // Rellenamos el importe del asiento
        $asiento->importe = $saldoDebe;
        if (false === $asiento->save()) {
            $asiento->delete();
            $database->rollback();
            return $asiento;
        }

        $database->commit();
        return $asiento;
    }

    /**
     * Calcula el importe de una cantidad (debe o haber) de una línea, sustituyendo
     * las variables por sus valores y evaluando la fórmula resultante.
     *
     * La variable Z es el saldo acumulado (debe - haber). Todos los valores se escriben sin
     * notación científica y la sustitución se hace en una sola pasada, para que el valor de
     * una variable nunca se vuelva a sustituir por otra.
     *
     * @param string $cantidad
     * @param AsientoPredefinidoVariable[] $variables
     * @param array $form valores ya validados por checkVariables()
     * @param float $saldoDebe
     * @param float $saldoHaber
     *
     * @return float
     * @throws \Throwable si la fórmula resultante no se puede evaluar
     */
    protected static function cantidadReplace(string $cantidad, array $variables, array $form, float $saldoDebe, float $saldoHaber): float
    {
        // reemplazamos las variables por sus valores, en una sola pasada
        $replacements = ['Z' => static::formatNumber($saldoDebe - $saldoHaber)];
        foreach ($variables as $var) {
            $replacements[$var->codigo] = static::formatNumber((float)$form['var_' . $var->codigo]);
        }
        $valor = strtr($cantidad, $replacements);

        // Si es una fórmula, calculamos
        foreach (['+', '-', '/', '*'] as $operator) {
            if (false !== strpos($valor, $operator)) {
                return (float)static::expressionLanguage()->evaluate($valor);
            }
        }

        return (float)$valor;
    }

    /**
     * Comprueba que la fórmula de una cantidad (debe o haber) se puede interpretar, tratando
     * cada letra de la A a la Z como una variable. Así se rechazan, por ejemplo, "A+", "1.21."
     * o variables pegadas a números u otras variables ("2A", "AB").
     *
     * @param string $cantidad fórmula en mayúsculas
     *
     * @return string mensaje de error, o cadena vacía si la fórmula es válida
     */
    public static function formulaError(string $cantidad): string
    {
        if ('' === trim($cantidad)) {
            return '';
        }

        try {
            static::expressionLanguage()->parse($cantidad, range('A', 'Z'));
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }

        return '';
    }

    /**
     * Devuelve la instancia compartida de ExpressionLanguage, para aprovechar su caché de expresiones.
     *
     * @return ExpressionLanguage
     */
    protected static function expressionLanguage(): ExpressionLanguage
    {
        return static::$expressionLanguage ??= new ExpressionLanguage();
    }

    /**
     * Convierte un número a texto con punto decimal y sin notación científica.
     * Usa 15 cifras significativas, las que un float representa sin ruido, por lo que
     * 1234567.89 se queda en "1234567.89", -2.5 en "-2.5" y 5.55E-17 en "0.0000000000000000555111512312578".
     *
     * @param float $numero
     *
     * @return string
     */
    protected static function formatNumber(float $numero): string
    {
        if (0.0 === $numero) {
            return '0';
        }

        $decimales = max(0, 14 - (int)floor(log10(abs($numero))));
        $texto = number_format($numero, $decimales, '.', '');
        if (str_contains($texto, '.')) {
            $texto = rtrim(rtrim($texto, '0'), '.');
        }

        return $texto === '-0' ? '0' : $texto;
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
        // reemplazamos las variables por sus valores tal cual se escribieron (para conservar
        // ceros a la izquierda), en una sola pasada para no volver a sustituir un valor ya puesto
        $replacements = [];
        foreach ($variables as $var) {
            $replacements[$var->codigo] = (string)$form['var_' . $var->codigo];
        }
        $valor = strtr($codsubcuenta, $replacements);

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

