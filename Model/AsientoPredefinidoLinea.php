<?php
/**
 * This file is part of AsientoPredefinido plugin for FacturaScripts
 * FacturaScripts       Copyright (C) 2015-2022 Carlos Garcia Gomez            <carlos@facturascripts.com>
 * AsientosPredefinidos Copyright (C) 2021-2022 Jeronimo Pedro Sánchez Manzano <socger@gmail.com>
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

namespace FacturaScripts\Plugins\AsientosPredefinidos\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\AsientoPredefinidoGenerator;

/**
 * Modelo que representa la línea de un asiento predefinido, con su subcuenta, contrapartida,
 * concepto, debe y haber.
 */
class AsientoPredefinidoLinea extends ModelClass
{
    use ModelTrait;

    /**
     * @var string código de la subcuenta de contrapartida
     */
    public $codcontrapartida;

    /**
     * @var string código de la subcuenta de la línea
     */
    public $codsubcuenta;

    /**
     * @var string concepto de la línea del asiento
     */
    public $concepto;

    /**
     * @var string importe del debe
     */
    public $debe;

    /**
     * @var string importe del haber
     */
    public $haber;

    /**
     * @var int clave primaria
     */
    public $id;

    /**
     * @var int id del asiento predefinido al que pertenece la línea
     */
    public $idasientopre;

    /**
     * @var int orden de la línea en el asiento predefinido
     */
    public $orden;

    public function clear(): void
    {
        parent::clear();
        $this->debe = '0';
        $this->haber = '0';
        $this->orden = 0;
    }

    public static function primaryColumn(): string
    {
        return "id";
    }

    public static function tableName(): string
    {
        return "asientospre_lineas";
    }

    public function test(): bool
    {
        $this->codcontrapartida = Tools::noHtml($this->codcontrapartida);
        $this->codsubcuenta = Tools::noHtml($this->codsubcuenta);
        $this->concepto = Tools::noHtml($this->concepto);

        // por la API pueden llegar vacíos (null): un debe o haber vacío es 0
        $this->debe = (string)($this->debe ?? '0');
        $this->haber = (string)($this->haber ?? '0');

        return $this->testSubcuenta((string)$this->codsubcuenta) &&
            $this->testSubcuenta((string)$this->codcontrapartida) &&
            $this->testCantidad($this->debe, 'debe') &&
            $this->testCantidad($this->haber, 'haber') &&
            parent::test();
    }

    private function testCantidad(string &$cantidad, string $etiqueta): bool
    {
        // reemplazamos la coma por punto, ponemos el cero a los decimales escritos sin él (.21)
        // y convertimos las letras de las variables a mayúsculas
        $cantidad = AsientoPredefinidoGenerator::normalizeFormula(strtoupper(str_replace(',', '.', $cantidad)));

        // quitamos de $cantidad lo que no sean números, letras mayúsculas, punto, signo menos, signo más, signo *, signo / y espacios
        $aceptados = preg_replace('/[^A-Z0-9\.\-\+\*\/\s]/', '', $cantidad);

        // Comprobamos si introdujo algún caracter no admitido
        if (strlen($aceptados) != strlen($cantidad)) {
            Tools::log()->warning('predefined-line-invalid-chars', [
                '%field%' => Tools::trans($etiqueta === 'debe' ? 'debit' : 'credit'),
                '%value%' => $cantidad,
            ]);
            return false;
        }

        // comprobamos que la fórmula se puede interpretar
        if ('' !== AsientoPredefinidoGenerator::formulaError($cantidad)) {
            Tools::log()->warning('predefined-line-invalid-formula', [
                '%field%' => Tools::trans($etiqueta === 'debe' ? 'debit' : 'credit'),
                '%line%' => $this->orden,
                '%subaccount%' => $this->codsubcuenta,
                '%formula%' => $cantidad,
            ]);
            return false;
        }

        return true;
    }

    private function testSubcuenta(string $codsubcuenta): bool
    {
        // Dejamos solo los caracteres aceptados ... números(0-9), letras en mayúsculas (A-Z) y el punto
        $aceptados = preg_replace("/[^A-Z0-9.]/", "", $codsubcuenta);

        // Comprobamos si introdujo algún caracter no admitido
        if (strlen($aceptados) != strlen($codsubcuenta)) {
            Tools::log()->warning('predefined-subaccount-invalid-chars', ['%subaccount%' => $codsubcuenta]);
            return false;
        }

        // Recorremos todos los caracteres admitidos para ver si hay más de una variable y para ver si han usado
        // la variable Z (es variable de resultados (descuadre del asiento)
        $contadorVariables = 0;
        for ($i = 0; $i < strlen($codsubcuenta); $i++) {
            $variable = preg_replace("/[^A-Z]/", "", $codsubcuenta[$i]);
            if ($variable === 'Z') {
                Tools::log()->warning('predefined-subaccount-z-variable', ['%subaccount%' => $codsubcuenta]);
                return false;
            }
            if (strlen($variable) > 0) {
                $contadorVariables++;
            }
        }
        if ($contadorVariables > 1) {
            Tools::log()->warning('predefined-subaccount-multiple-variables', ['%subaccount%' => $codsubcuenta]);
            return false;
        }

        // Comprobamos que no hubiera más de un punto
        $puntos = substr_count($codsubcuenta, '.');
        if ($puntos > 1) {
            Tools::log()->warning('predefined-subaccount-multiple-dots', ['%subaccount%' => $codsubcuenta]);
            return false;
        }

        return true;
    }
}
