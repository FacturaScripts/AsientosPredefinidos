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
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\AsientoPredefinido;
use FacturaScripts\Dinamic\Model\AsientoPredefinidoLinea;
use FacturaScripts\Dinamic\Model\AsientoPredefinidoVariable;

/**
 * Restaura los asientos predefinidos que incluye el plugin (Data/Codpais/XXX).
 *
 * Las plantillas se identifican por su descripción, no por el id del CSV: así no se
 * sobrescriben las plantillas que el usuario haya creado con esos ids en versiones
 * anteriores, y los registros nuevos toman el id de la secuencia de cada tabla.
 */
class AsientoPredefinidoRestorer
{
    /**
     * Crea o actualiza las plantillas del país indicado (o de España si no hay datos para ese país),
     * sustituyendo sus líneas y variables. Las demás plantillas no se tocan.
     *
     * @param string $codpais
     *
     * @return bool
     */
    public static function restore(string $codpais): bool
    {
        $basePath = dirname(__DIR__) . '/Data/Codpais/' . $codpais;
        if ('' === $codpais || false === is_dir($basePath)) {
            $basePath = dirname(__DIR__) . '/Data/Codpais/ESP';
        }

        $plantillas = static::readCsv($basePath . '/asientospre.csv');
        $lineas = static::readCsv($basePath . '/asientospre_lineas.csv');
        $variables = static::readCsv($basePath . '/asientospre_variables.csv');
        if (null === $plantillas || null === $lineas || null === $variables) {
            return false;
        }
        if (empty($plantillas)) {
            Tools::log()->error('record-save-error');
            return false;
        }

        $lineasPorPlantilla = static::groupBy($lineas, 'idasientopre');
        $variablesPorPlantilla = static::groupBy($variables, 'idasientopre');

        $database = new DataBase();
        $database->beginTransaction();

        foreach ($plantillas as $plantilla) {
            $idCsv = $plantilla['id'] ?? '';
            $predefinido = static::savePlantilla($plantilla);
            if (null === $predefinido
                || false === static::deleteChildren($predefinido)
                || false === static::saveVariables($predefinido, $variablesPorPlantilla[$idCsv] ?? [])
                || false === static::saveLineas($predefinido, $lineasPorPlantilla[$idCsv] ?? [])) {
                $database->rollback();
                Tools::log()->error('record-save-error');
                return false;
            }
        }

        $database->commit();
        return true;
    }

    protected static function deleteChildren(AsientoPredefinido $predefinido): bool
    {
        foreach ($predefinido->getLines() as $linea) {
            if (false === $linea->delete()) {
                return false;
            }
        }

        foreach ($predefinido->getVariables() as $variable) {
            if (false === $variable->delete()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Agrupa las filas de un CSV por el valor de una columna.
     *
     * @param array[] $rows
     * @param string $column
     *
     * @return array[][]
     */
    protected static function groupBy(array $rows, string $column): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row[$column] ?? ''][] = $row;
        }
        return $groups;
    }

    /**
     * Lee un CSV con cabecera y devuelve sus filas como arrays asociativos, o null si no se puede leer.
     *
     * @param string $filePath
     *
     * @return array[]|null
     */
    protected static function readCsv(string $filePath): ?array
    {
        $handle = is_file($filePath) ? fopen($filePath, 'rb') : false;
        if (false === $handle) {
            Tools::log()->warning('file-not-found', ['%fileName%' => basename($filePath)]);
            return null;
        }

        $rows = [];
        $headers = fgetcsv($handle, 0, ',', '"', '\\');
        while (is_array($headers) && false !== ($row = fgetcsv($handle, 0, ',', '"', '\\'))) {
            if (count($row) === count($headers)) {
                $rows[] = array_combine($headers, $row);
            }
        }
        fclose($handle);

        return $rows;
    }

    protected static function saveLineas(AsientoPredefinido $predefinido, array $rows): bool
    {
        foreach ($rows as $row) {
            $linea = new AsientoPredefinidoLinea();
            $linea->idasientopre = $predefinido->id;
            $linea->codsubcuenta = $row['codsubcuenta'] ?? '';
            $linea->codcontrapartida = $row['codcontrapartida'] ?? '';
            $linea->concepto = $row['concepto'] ?? '';
            $linea->debe = '' === ($row['debe'] ?? '') ? '0' : $row['debe'];
            $linea->haber = '' === ($row['haber'] ?? '') ? '0' : $row['haber'];
            $linea->orden = (int)($row['orden'] ?? 0);
            if (false === $linea->save()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Busca la plantilla por su descripción y la crea o actualiza con los datos del CSV.
     *
     * @param array $row
     *
     * @return AsientoPredefinido|null
     */
    protected static function savePlantilla(array $row): ?AsientoPredefinido
    {
        $descripcion = $row['descripcion'] ?? '';
        $predefinido = new AsientoPredefinido();
        if ('' === $descripcion || false === $predefinido->loadWhere([Where::eq('descripcion', $descripcion)])) {
            $predefinido->clear();
        }

        $predefinido->descripcion = $descripcion;
        $predefinido->concepto = $row['concepto'] ?? '';
        $predefinido->textoayuda = $row['textoayuda'] ?? '';
        return $predefinido->save() ? $predefinido : null;
    }

    protected static function saveVariables(AsientoPredefinido $predefinido, array $rows): bool
    {
        foreach ($rows as $row) {
            $variable = new AsientoPredefinidoVariable();
            $variable->idasientopre = $predefinido->id;
            $variable->codigo = $row['codigo'] ?? '';
            $variable->mensaje = $row['mensaje'] ?? '';
            if (false === $variable->save()) {
                return false;
            }
        }

        return true;
    }
}
