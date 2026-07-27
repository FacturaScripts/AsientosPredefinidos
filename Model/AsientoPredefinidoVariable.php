<?php
/**
 * This file is part of AsientoPredefinido plugin for FacturaScripts
 * Copyright (C) 2021-2022 Carlos Garcia Gomez            <carlos@facturascripts.com>
 *                         Jeronimo Pedro Sánchez Manzano <socger@gmail.com>
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

class AsientoPredefinidoVariable extends ModelClass
{
    use ModelTrait;

    /**
     * @var string código de la variable
     */
    public $codigo;

    /**
     * @var int clave primaria
     */
    public $id;

    /**
     * @var int id del asiento predefinido al que pertenece la variable
     */
    public $idasientopre;

    /**
     * @var string mensaje mostrado al usuario para solicitar el valor de la variable
     */
    public $mensaje;

    public static function primaryColumn(): string
    {
        return "id";
    }

    public static function tableName(): string
    {
        return "asientospre_variables";
    }

    public function test(): bool
    {
        $this->codigo = strtoupper(Tools::noHtml($this->codigo));
        $this->mensaje = Tools::noHtml($this->mensaje);

        if ($this->codigo === 'Z') {
            Tools::log()->warning('No es necesario registrar la variable Z.');
            return false;
        }

        return parent::test();
    }
}
