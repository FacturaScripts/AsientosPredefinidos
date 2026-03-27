<?php
/**
 * This file is part of AsientoPredefinido plugin for FacturaScripts
 * Copyright (C) 2021-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Plugins\AsientosPredefinidos\Model\AsientoPredefinido;
use FacturaScripts\Plugins\AsientosPredefinidos\Model\AsientoPredefinidoLinea;
use FacturaScripts\Plugins\AsientosPredefinidos\Model\AsientoPredefinidoVariable;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * @author Carlos García Gómez            <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez       <hola@danielfg.es>
 * @author Jeronimo Pedro Sánchez Manzano <socger@gmail.com>
 */
class AsientoPredefinidoGenerator
{
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
        $asiento->concepto = CodePatterns::trans($predefinido->concepto, $asiento);
        $asiento->canal = $form["canal"] ?? null;
        if (false === $asiento->save()) {
            Tools::log()->warning('no-can-create-accounting-entry');
            $database->rollback();
            return $asiento; // Devolvemos el asiento incompleto, vacío.
        }

        // Recorremos todas las líneas/partidas del asiento predefinido para crearlas en el asiento que estamos creando

        $saldoDebe = 0.0;
        $saldoHaber = 0.0;
        foreach ($lines as $line) {
            // Creamos la partida
            $newLine = $asiento->getNewLine();
            $newLine->concepto = CodePatterns::trans($line->concepto, $asiento);
            $newLine->debe = static::cantidadReplace($line->debe, $variables, $form, $saldoDebe, $saldoHaber);
            $newLine->haber = static::cantidadReplace($line->haber, $variables, $form, $saldoDebe, $saldoHaber);

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
     * @param string $cantidad
     * @param AsientoPredefinidoVariable[] $variables
     * @param array $form
     * @param float $saldoDebe
     * @param float $saldoHaber
     *
     * @return float
     */
    protected static function cantidadReplace(string $cantidad, array $variables, array $form, float $saldoDebe, float $saldoHaber): float
    {
        // reemplazamos las variables por sus valores
        $search = ['Z'];
        $replace = [$saldoDebe - $saldoHaber];
        foreach ($variables as $var) {
            $search[] = $var->codigo;
            $replace[] = $form['var_' . $var->codigo];
        }
        $valor = str_replace($search, $replace, $cantidad);

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
        if (false === $subcuenta->loadFromCode('', $where)) {
            Tools::log()->warning('subaccount-not-found', ['%subAccountCode%' => $valorFinal]);
        }
        return $subcuenta;
    }
}


