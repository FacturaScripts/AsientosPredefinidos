<?php
/**
 * This file is part of AsientosPredefinidos plugin for FacturaScripts
 * Copyright (C) 2022-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Plugins\AsientosPredefinidos;

use FacturaScripts\Core\Template\InitClass;

require_once __DIR__ . '/vendor/autoload.php';

class Init extends InitClass
{
    public function init(): void
    {
        // se ejecuta cada vez que carga FacturaScripts (si este plugin está activado).
        $this->loadExtension(new Extension\Controller\ListAsiento());
    }

    public function uninstall(): void
    {
    }

    public function update(): void
    {
        // Importar/Actualizar tablas desde los CSV incluidos en el plugin
        // Esto asegura que nuevas plantillas en Data/Codpais/ESP se sincronicen con la BBDD
        try {
            $tables = ['asientospre', 'asientospre_lineas', 'asientospre_variables'];
            $database = new \FacturaScripts\Core\Base\DataBase();
            foreach ($tables as $table) {
                $file = __DIR__ . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'Codpais' . DIRECTORY_SEPARATOR . 'ESP' . DIRECTORY_SEPARATOR . $table . '.csv';
                if (file_exists($file)) {
                    $sql = \FacturaScripts\Core\Lib\Import\CSVImport::importFileSQL($table, $file, true);
                    if (!empty($sql)) {
                        $database->query($sql);
                    }
                }
            }
        } catch (\Throwable $e) {
            // no interrumpir la actualización por un error de importación; logueamos
            \FacturaScripts\Core\Tools::log()->warning('asientospredefinidos-import-error', ['message' => $e->getMessage()]);
        }
    }
}
