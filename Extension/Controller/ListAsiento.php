<?php
/**
 * This file is part of AsientoPredefinido plugin for FacturaScripts
 * Copyright (C) 2021-2026 Carlos Garcia Gomez            <carlos@facturascripts.com>
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

namespace FacturaScripts\Plugins\AsientosPredefinidos\Extension\Controller;

use Closure;
use FacturaScripts\Core\Lib\Import\CSVImport;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AsientosPredefinidos\Model\AsientoPredefinido;
use FacturaScripts\Plugins\AsientosPredefinidos\Model\AsientoPredefinidoLinea;
use FacturaScripts\Plugins\AsientosPredefinidos\Model\AsientoPredefinidoVariable;

class ListAsiento
{
    public function createViews(): Closure
    {
        return function () {
            $this->createViewsAsientosPredefinidos();

            // Esto es para añadir un filtro en la pestaña ListAsiento
            $asientosPre = $this->codeModel->all('asientospre', 'id', 'descripcion');
            $this->addFilterSelect('ListAsiento', 'idasientopre', 'predefined-acc-entries', 'idasientopre', $asientosPre);
        };
    }

    protected function createViewsAsientosPredefinidos(): Closure
    {
        return function (string $viewName = 'ListAsientoPredefinido') {
            $this->addView($viewName, 'AsientoPredefinido', 'predefined-acc-entries', 'fa-solid fa-blender')
                ->addOrderBy(['id'], 'code', 1)
                ->addOrderBy(['descripcion'], 'description')
                ->addSearchFields(['id', 'concepto', 'descripcion']);

            if ($this->user->admin) {
                $this->tab($viewName)->addButton([
                    'action' => 'restore-predefined',
                    'color' => 'warning',
                    'confirm' => true,
                    'icon' => 'fa-solid fa-trash-restore',
                    'label' => 'restore'
                ]);
            }
        };
    }

    public function execPreviousAction(): Closure
    {
        return function ($action) {
            if ($action !== 'restore-predefined') {
                return;
            }

            if (false === $this->user->admin || false === $this->permissions->allowUpdate) {
                Tools::log()->warning('not-allowed-modify');
                return;
            } elseif (false === $this->validateFormToken()) {
                return;
            }

            $codpais = Tools::settings('default', 'codpais', 'ESP');
            $basePath = dirname(__DIR__, 2) . '/Data/Codpais/' . $codpais;
            if (false === is_dir($basePath)) {
                $basePath = dirname(__DIR__, 2) . '/Data/Codpais/ESP';
            }

            $preFile = $basePath . '/asientospre.csv';
            $lineFile = $basePath . '/asientospre_lineas.csv';
            $varFile = $basePath . '/asientospre_variables.csv';
            foreach ([$preFile, $lineFile, $varFile] as $filePath) {
                if (false === file_exists($filePath)) {
                    Tools::log()->warning('file-not-found');
                    return;
                }
            }

            $templateIds = [];
            $handle = fopen($preFile, 'rb');
            if (false === $handle) {
                Tools::log()->error('record-save-error');
                return;
            }

            $headers = fgetcsv($handle);
            $idColumn = false === is_array($headers) ? false : array_search('id', $headers, true);
            while (false !== $idColumn && ($row = fgetcsv($handle)) !== false) {
                if (empty($row[$idColumn])) {
                    continue;
                }

                $templateIds[] = (int)$row[$idColumn];
            }
            fclose($handle);

            $templateIds = array_values(array_unique(array_filter($templateIds)));
            if (empty($templateIds)) {
                Tools::log()->error('record-save-error');
                return;
            }

            $in = implode(',', array_map('intval', $templateIds));
            $mainSql = CSVImport::importFileSQL(AsientoPredefinido::tableName(), $preFile, true);
            $lineSql = CSVImport::importFileSQL(AsientoPredefinidoLinea::tableName(), $lineFile);
            $varSql = CSVImport::importFileSQL(AsientoPredefinidoVariable::tableName(), $varFile);
            if (empty($mainSql) || empty($lineSql) || empty($varSql)) {
                Tools::log()->error('record-save-error');
                return;
            }

            $this->dataBase->beginTransaction();

            if (
                false === $this->dataBase->exec($mainSql)
                || false === $this->dataBase->exec(
                    'DELETE FROM ' . AsientoPredefinidoLinea::tableName() . ' WHERE idasientopre IN (' . $in . ');'
                )
                || false === $this->dataBase->exec(
                    'DELETE FROM ' . AsientoPredefinidoVariable::tableName() . ' WHERE idasientopre IN (' . $in . ');'
                )
                || false === $this->dataBase->exec($lineSql)
                || false === $this->dataBase->exec($varSql)
            ) {
                $this->dataBase->rollback();
                Tools::log()->error('record-save-error');
                return;
            }

            $this->dataBase->commit();
            Tools::log()->notice('record-updated-correctly');
        };
    }
}
