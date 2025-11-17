<?php
/**
 * This file is part of AsientosPredefinidos plugin for FacturaScripts
 * Copyright (C) 2023-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\DataSrc\Empresas;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AsientosPredefinidos\Model\AsientoPredefinido;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class AsientosPredefinidosTest extends TestCase
{
    use DefaultSettingsTrait;
    use LogErrorsTrait;
    use RandomDataTrait;

    public static function setUpBeforeClass(): void
    {
        self::setDefaultSettings();
        self::installAccountingPlan();
        self::removeTaxRegularization();
    }

    public function testAsientoPredefinidoNomina(): void
    {
        // obtenemos la empresa predefinida
        $empresa = Empresas::default();

        // cargamos el asiento predefinido
        $asientoPredefinido = new AsientoPredefinido();
        $this->assertTrue($asientoPredefinido->loadFromCode(1));

        // generamos el asiento
        $asiento = $asientoPredefinido->generate([
            'idempresa' => $empresa->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
            'var_A' => 0,
            'var_B' => 0,
            'var_C' => 20,
            'var_L' => 30,
            'var_R' => 40,
            'var_D' => 50,
        ]);

        // Comprobamos que el asiento que ha creado correctamente
        $this->assertTrue($asiento->exists());

        $textoMes = Tools::lang()->trans(strtolower(date('F', strtotime($asiento->fecha))));
        $this->assertEquals('Nómina ' . $textoMes, $asiento->concepto);
        $this->assertEquals(20, $asiento->importe);

        // Comprobamos que las partidas se hayan generado correctamente.
        $partidas = $asiento->getLines();

        $this->assertEquals('4650000000', $partidas[0]->codsubcuenta);
        $this->assertEquals('Pendiente de pago', $partidas[0]->concepto);
        $this->assertEquals(0, $partidas[0]->debe);
        $this->assertEquals(30, $partidas[0]->haber);

        $this->assertEquals('4751000000', $partidas[1]->codsubcuenta);
        $this->assertEquals('Retenciones', $partidas[1]->concepto);
        $this->assertEquals(0, $partidas[1]->debe);
        $this->assertEquals(40, $partidas[1]->haber);

        $this->assertEquals('4760000000', $partidas[2]->codsubcuenta);
        $this->assertEquals('Seguridad Social Acreedora', $partidas[2]->concepto);
        $this->assertEquals(0, $partidas[2]->debe);
        $this->assertEquals(-50, $partidas[2]->haber);

        $this->assertEquals('6400000000', $partidas[3]->codsubcuenta);
        $this->assertEquals('Sueldo', $partidas[3]->concepto);
        $this->assertEquals(50, $partidas[3]->debe);
        $this->assertEquals(0, $partidas[3]->haber);

        $this->assertEquals('6420000000', $partidas[4]->codsubcuenta);
        $this->assertEquals('Seguridad Social Empresa', $partidas[4]->concepto);
        $this->assertEquals(-30, $partidas[4]->debe);
        $this->assertEquals(0, $partidas[4]->haber);

        // borramos el asiento
        $asiento->delete();
    }

    public function testAsientoPredefinidoCuotaAutonomo(): void
    {
        // obtenemos la empresa predefinida
        $empresa = Empresas::default();

        // cargamos el asiento predefinido
        $asientoPredefinido = new AsientoPredefinido();
        $this->assertTrue($asientoPredefinido->loadFromCode(2));

        // generamos el asiento
        $asiento = $asientoPredefinido->generate([
            'idempresa' => $empresa->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
            'var_A' => 0,
            'var_B' => 123,
        ]);

        // Comprobamos que el asiento que ha creado correctamente
        $textoMes = Tools::lang()->trans(strtolower(date('F', strtotime($asiento->fecha))));
        $this->assertEquals('Cuota de autónomo ' . $textoMes, $asiento->concepto);
        $this->assertEquals(123, $asiento->importe);

        // Comprobamos que las partidas se hayan generado correctamente.
        $partidas = $asiento->getLines();

        $this->assertEquals('4760000000', $partidas[0]->codsubcuenta);
        $this->assertEquals('Cuota autónomo ' . $textoMes, $partidas[0]->concepto);
        $this->assertEquals(0, $partidas[0]->debe);
        $this->assertEquals(123, $partidas[0]->haber);

        $this->assertEquals('6420000000', $partidas[1]->codsubcuenta);
        $this->assertEquals('Cuota autónomo ' . $textoMes, $partidas[1]->concepto);
        $this->assertEquals(123, $partidas[1]->debe);
        $this->assertEquals(0, $partidas[1]->haber);

        // borramos el asiento
        $asiento->delete();
    }

    public function testAsientoPredefinidoPagoCuotaAutonomo(): void
    {
        // obtenemos la empresa predefinida
        $empresa = Empresas::default();

        // cargamos el asiento predefinido
        $asientoPredefinido = new AsientoPredefinido();
        $this->assertTrue($asientoPredefinido->loadFromCode(3));

        // generamos el asiento
        $asiento = $asientoPredefinido->generate([
            'idempresa' => $empresa->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
            'var_A' => 0,
            'var_B' => 123,
        ]);

        // Comprobamos que el asiento que ha creado correctamente
        $textoMes = Tools::lang()->trans(strtolower(date('F', strtotime($asiento->fecha))));
        $this->assertEquals('Pago cuota autónomo ' . $textoMes, $asiento->concepto);
        $this->assertEquals(123, $asiento->importe);

        // Comprobamos que las partidas se hayan generado correctamente.
        $partidas = $asiento->getLines();

        $this->assertEquals('4760000000', $partidas[0]->codsubcuenta);
        $this->assertEquals('Pago cuota autónomo ' . $textoMes, $partidas[0]->concepto);
        $this->assertEquals(123, $partidas[0]->debe);
        $this->assertEquals(0, $partidas[0]->haber);

        $this->assertEquals('5720000000', $partidas[1]->codsubcuenta);
        $this->assertEquals('Pago cuota autónomo ' . $textoMes, $partidas[1]->concepto);
        $this->assertEquals(0, $partidas[1]->debe);
        $this->assertEquals(123, $partidas[1]->haber);

        // borramos el asiento
        $asiento->delete();
    }

    public function testAsientoPredefinidoPagoNomina(): void
    {
        // obtenemos la empresa predefinida
        $empresa = Empresas::default();

        // cargamos el asiento predefinido
        $asientoPredefinido = new AsientoPredefinido();
        $this->assertTrue($asientoPredefinido->loadFromCode(4));

        // generamos el asiento
        $asiento = $asientoPredefinido->generate([
            'idempresa' => $empresa->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
            'var_A' => 0,
            'var_B' => 0,
            'var_I' => 123,
        ]);

        // Comprobamos que el asiento que ha creado correctamente
        $textoMes = Tools::lang()->trans(strtolower(date('F', strtotime($asiento->fecha))));
        $this->assertEquals('Pago nómina ' . $textoMes, $asiento->concepto);
        $this->assertEquals(123, $asiento->importe);

        // Comprobamos que las partidas se hayan generado correctamente.
        $partidas = $asiento->getLines();

        $this->assertEquals('4650000000', $partidas[0]->codsubcuenta);
        $this->assertEquals('Pago nómina ' . $textoMes, $partidas[0]->concepto);
        $this->assertEquals(123, $partidas[0]->debe);
        $this->assertEquals(0, $partidas[0]->haber);

        $this->assertEquals('5720000000', $partidas[1]->codsubcuenta);
        $this->assertEquals('Pago nómina ' . $textoMes, $partidas[1]->concepto);
        $this->assertEquals(0, $partidas[1]->debe);
        $this->assertEquals(123, $partidas[1]->haber);

        // borramos el asiento
        $asiento->delete();
    }

    public function testMethodTest(): void
    {
        $asientoPredefinido = new AsientoPredefinido();
        $asientoPredefinido->descripcion = null;
        $asientoPredefinido->concepto = null;
        $this->assertTrue($asientoPredefinido->test());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
