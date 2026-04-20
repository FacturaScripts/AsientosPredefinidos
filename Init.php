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
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Base\DataBase;
use Throwable;

require_once __DIR__ . '/vendor/autoload.php';

class Init extends InitClass
{
    public function init(): void
    {
        $this->loadExtension(new Extension\Controller\ListAsiento());
    }

    public function uninstall(): void {}

    public function update(): void
    {
        $db = new DataBase();

        // 1. Crear tabla asientospre_ayudas si no existe (MySQL/MariaDB)
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS `asientospre_ayudas` (
                    `id` INT NOT NULL AUTO_INCREMENT,
                    `idasientopre` INT NOT NULL,
                    `cuando` TEXT,
                    `ejemplo` TEXT,
                    `nota` TEXT,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_asientospre_ayudas_idasientopre` (`idasientopre`),
                    CONSTRAINT `ca_asientospre_ayudas_asientospre`
                        FOREIGN KEY (`idasientopre`)
                        REFERENCES `asientospre` (`id`)
                        ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
            );
        } catch (Throwable $e) {
            Tools::log()->warning('asientospredefinidos-create-ayudas: ' . $e->getMessage());
        }

        // 2. Importar cada CSV con REPLACE INTO (actualiza siempre, no solo si no existe)
        $tables = [
            'asientospre'          => ['id', 'descripcion', 'concepto'],
            'asientospre_lineas'   => ['codsubcuenta', 'concepto', 'codcontrapartida', 'debe', 'haber', 'id', 'idasientopre', 'orden'],
            'asientospre_variables'=> ['codigo', 'mensaje', 'id', 'idasientopre'],
            'asientospre_ayudas'   => ['id', 'idasientopre', 'cuando', 'ejemplo', 'nota'],
        ];

        foreach ($tables as $table => $columns) {
            if (!$db->tableExists($table)) {
                continue;
            }

            $file = __DIR__ . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR
                . 'Codpais' . DIRECTORY_SEPARATOR . 'ESP' . DIRECTORY_SEPARATOR
                . $table . '.csv';

            if (!file_exists($file)) {
                continue;
            }

            if (!$this->importCsvReplace($db, $table, $columns, $file)) {
                Tools::log()->error('asientospredefinidos-import-error: ' . $table);
            }
        }
    }

    /**
     * Importa un CSV usando REPLACE INTO para que siempre actualice,
     * independientemente de si los registros ya existen.
     */
    private function importCsvReplace(DataBase $db, string $table, array $columns, string $file): bool
    {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return false;
        }

        // Leer y descartar la cabecera
        fgetcsv($handle);

        $colList = '`' . implode('`, `', $columns) . '`';
        $ok = true;
        $batch = [];
        $batchSize = 50;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < count($columns)) {
                continue;
            }

            // Reordenar los valores según el orden de columnas del CSV original
            // El CSV tiene las columnas en el mismo orden que $columns
            $values = [];
            foreach ($row as $i => $val) {
                if ($i >= count($columns)) {
                    break;
                }
                $values[] = $db->var2str($val);
            }

            $batch[] = '(' . implode(', ', $values) . ')';

            if (count($batch) >= $batchSize) {
                $sql = "REPLACE INTO `{$table}` ({$colList}) VALUES " . implode(', ', $batch) . ';';
                if (!$db->exec($sql)) {
                    $ok = false;
                }
                $batch = [];
            }
        }

        // Insertar el resto del lote
        if (!empty($batch)) {
            $sql = "REPLACE INTO `{$table}` ({$colList}) VALUES " . implode(', ', $batch) . ';';
            if (!$db->exec($sql)) {
                $ok = false;
            }
        }

        fclose($handle);
        return $ok;
    }
}
