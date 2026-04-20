<?php
/**
 * This file is part of AsientosPredefinidos plugin for FacturaScripts
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use FacturaScripts\Core\Base\DataBase;

/**
 * Ayuda/ejemplo asociado a un asiento predefinido.
 * Leemos directamente con DataBase para evitar que ModelTrait
 * haga checkTable() antes de que el deploy haya creado la tabla.
 */
class AsientoPredefinidoAyuda
{
    /** @var int */
    public $id;

    /** @var int */
    public $idasientopre;

    /** @var string */
    public $cuando;

    /** @var string */
    public $ejemplo;

    /** @var string|null */
    public $nota;

    /**
     * Devuelve la ayuda para un asiento predefinido concreto.
     * Devuelve null si la tabla no existe todavía o no hay registro.
     */
    public static function getByAsiento(int $idasientopre): ?self
    {
        $db = new DataBase();

        // Verificar que la tabla existe antes de hacer ninguna consulta
        if (!$db->tableExists('asientospre_ayudas')) {
            return null;
        }

        $sql = 'SELECT * FROM asientospre_ayudas WHERE idasientopre = '
            . $db->var2str($idasientopre) . ' LIMIT 1';

        $rows = $db->select($sql);
        if (empty($rows)) {
            return null;
        }

        $obj = new self();
        $obj->id            = (int)$rows[0]['id'];
        $obj->idasientopre  = (int)$rows[0]['idasientopre'];
        $obj->cuando        = $rows[0]['cuando'] ?? '';
        $obj->ejemplo       = $rows[0]['ejemplo'] ?? '';
        $obj->nota          = $rows[0]['nota'] ?? null;

        return $obj;
    }
}
