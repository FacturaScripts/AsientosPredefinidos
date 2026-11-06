<?php
/**
 * This file is part of AsientosPredefinidos plugin for FacturaScripts
 * Copyright (C) 2023-2026 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\MiniLog;
use FacturaScripts\Core\DataSrc\Empresas;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\AsientoPredefinidoRestorer;
use FacturaScripts\Dinamic\Model\Asiento;
use FacturaScripts\Dinamic\Model\AsientoPredefinido;
use FacturaScripts\Dinamic\Model\AsientoPredefinidoLinea;
use FacturaScripts\Dinamic\Model\AsientoPredefinidoVariable;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class AsientosPredefinidosTest extends TestCase
{
    use DefaultSettingsTrait;
    use LogErrorsTrait;
    use RandomDataTrait;

    /** @var Asiento[] asientos generados por el test, para borrarlos al terminar */
    private $asientos = [];

    /** @var AsientoPredefinido[] plantillas creadas por el test, para borrarlas al terminar */
    private $predefinidos = [];

    public static function setUpBeforeClass(): void
    {
        self::setDefaultSettings();
        self::loadCoreModels();
        self::installAccountingPlan();
        self::removeTaxRegularization();
    }

    public function testAsientoPredefinidoNomina(): void
    {
        // obtenemos la empresa predefinida
        $empresa = Empresas::default();

        // cargamos el asiento predefinido
        $asientoPredefinido = new AsientoPredefinido();
        $this->assertTrue($asientoPredefinido->load(1));

        // generamos el asiento
        $asiento = $asientoPredefinido->generate([
            'idempresa' => $empresa->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
            'var_A' => 0,
            'var_B' => 0,
            'var_S' => 500,
            'var_L' => 1200,
            'var_R' => 200,
            'var_D' => 1500,
        ]);

        // Comprobamos que el asiento que ha creado correctamente
        $this->assertTrue($asiento->exists());

        $textoMes = Tools::lang()->trans(strtolower(date('F', strtotime($asiento->fecha))));
        $this->assertEquals('Nómina ' . $textoMes, $asiento->concepto);
        $this->assertEquals(2000, $asiento->importe);

        // Comprobamos que las partidas se hayan generado correctamente.
        $partidas = $asiento->getLines();
        $this->assertCount(5, $partidas);

        $totalDebe = 0.0;
        $totalHaber = 0.0;
        foreach ($partidas as $partida) {
            $totalDebe += (float)$partida->debe;
            $totalHaber += (float)$partida->haber;
        }
        $this->assertEquals($totalDebe, $totalHaber);
        $this->assertEquals($asiento->importe, $totalDebe);

        $this->assertEquals('6400000000', $partidas[0]->codsubcuenta);
        $this->assertEquals('Sueldo', $partidas[0]->concepto);
        $this->assertEquals(1500, $partidas[0]->debe);
        $this->assertEquals(0, $partidas[0]->haber);

        $this->assertEquals('6420000000', $partidas[1]->codsubcuenta);
        $this->assertEquals('Seguridad Social Empresa', $partidas[1]->concepto);
        $this->assertEquals(500, $partidas[1]->debe);
        $this->assertEquals(0, $partidas[1]->haber);

        $this->assertEquals('4751000000', $partidas[2]->codsubcuenta);
        $this->assertEquals('Retenciones', $partidas[2]->concepto);
        $this->assertEquals(0, $partidas[2]->debe);
        $this->assertEquals(200, $partidas[2]->haber);

        $this->assertEquals('4650000000', $partidas[3]->codsubcuenta);
        $this->assertEquals('Pendiente de pago', $partidas[3]->concepto);
        $this->assertEquals(0, $partidas[3]->debe);
        $this->assertEquals(1200, $partidas[3]->haber);

        $this->assertEquals('4760000000', $partidas[4]->codsubcuenta);
        $this->assertEquals('Seguridad Social Acreedora', $partidas[4]->concepto);
        $this->assertEquals(0, $partidas[4]->debe);
        $this->assertEquals(600, $partidas[4]->haber);

        // borramos el asiento
        $asiento->delete();
    }

    public function testAsientoPredefinidoNominaSinRetencion(): void
    {
        // obtenemos la empresa predefinida
        $empresa = Empresas::default();

        // cargamos el asiento predefinido
        $asientoPredefinido = new AsientoPredefinido();
        $this->assertTrue($asientoPredefinido->load(1));

        // generamos el asiento: sin retención y con SS trabajador nula (L = D)
        $asiento = $asientoPredefinido->generate([
            'idempresa' => $empresa->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
            'var_A' => 0,
            'var_B' => 0,
            'var_S' => 300,
            'var_L' => 1000,
            'var_R' => 0,
            'var_D' => 1000,
        ]);

        $this->assertTrue($asiento->exists());
        $this->assertEquals(1300, $asiento->importe);

        $partidas = $asiento->getLines();
        $this->assertCount(5, $partidas);

        $totalDebe = 0.0;
        $totalHaber = 0.0;
        foreach ($partidas as $partida) {
            $totalDebe += (float)$partida->debe;
            $totalHaber += (float)$partida->haber;
        }
        $this->assertEquals($totalDebe, $totalHaber);

        // Z = (D + S) - (R + L) = 1300 - 1000 = 300
        $this->assertEquals('4760000000', $partidas[4]->codsubcuenta);
        $this->assertEquals(0, $partidas[4]->debe);
        $this->assertEquals(300, $partidas[4]->haber);

        // Retención a cero
        $this->assertEquals('4751000000', $partidas[2]->codsubcuenta);
        $this->assertEquals(0, $partidas[2]->debe);
        $this->assertEquals(0, $partidas[2]->haber);

        $asiento->delete();
    }

    public function testAsientoPredefinidoCuotaAutonomo(): void
    {
        // obtenemos la empresa predefinida
        $empresa = Empresas::default();

        // cargamos el asiento predefinido
        $asientoPredefinido = new AsientoPredefinido();
        $this->assertTrue($asientoPredefinido->load(3));

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

        $this->assertEquals('6420000000', $partidas[0]->codsubcuenta);
        $this->assertEquals('Cuota autónomo ' . $textoMes, $partidas[0]->concepto);
        $this->assertEquals(123, $partidas[0]->debe);
        $this->assertEquals(0, $partidas[0]->haber);

        $this->assertEquals('4760000000', $partidas[1]->codsubcuenta);
        $this->assertEquals('Cuota autónomo ' . $textoMes, $partidas[1]->concepto);
        $this->assertEquals(0, $partidas[1]->debe);
        $this->assertEquals(123, $partidas[1]->haber);

        // borramos el asiento
        $asiento->delete();
    }

    public function testAsientoPredefinidoPagoCuotaAutonomo(): void
    {
        // obtenemos la empresa predefinida
        $empresa = Empresas::default();

        // cargamos el asiento predefinido
        $asientoPredefinido = new AsientoPredefinido();
        $this->assertTrue($asientoPredefinido->load(4));

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
        $this->assertTrue($asientoPredefinido->load(2));

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

    public function testAsientoPredefinidoPagoModelo303(): void
    {
        // obtenemos la empresa predefinida
        $empresa = Empresas::default();

        // cargamos el asiento predefinido
        $asientoPredefinido = new AsientoPredefinido();
        $this->assertTrue($asientoPredefinido->load(5));

        // generamos el asiento
        $asiento = $asientoPredefinido->generate([
            'idempresa' => $empresa->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
            'var_A' => 0,
            'var_B' => 123,
            'var_C' => 0,
        ]);

        // Comprobamos que el asiento que ha creado correctamente
        $this->assertTrue($asiento->exists());
        $this->assertEquals(123, $asiento->importe);

        // Comprobamos que las partidas se hayan generado correctamente.
        $partidas = $asiento->getLines();

        $this->assertCount(2, $partidas);

        $this->assertEquals('4750000000', $partidas[0]->codsubcuenta);
        $this->assertEquals('Pago IVA modelo 303', $partidas[0]->concepto);
        $this->assertEquals(123, $partidas[0]->debe);
        $this->assertEquals(0, $partidas[0]->haber);

        $this->assertEquals('5720000000', $partidas[1]->codsubcuenta);
        $this->assertEquals('Pago IVA modelo 303', $partidas[1]->concepto);
        $this->assertEquals(0, $partidas[1]->debe);
        $this->assertEquals(123, $partidas[1]->haber);

        // borramos el asiento
        $asiento->delete();
    }

    public function testAsientoPredefinidoPagoModelo111(): void
    {
        $empresa = Empresas::default();

        $asientoPredefinido = new AsientoPredefinido();
        $this->assertTrue($asientoPredefinido->load(6));

        $asiento = $asientoPredefinido->generate([
            'idempresa' => $empresa->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
            'var_A' => 0,
            'var_B' => 250,
            'var_C' => 0,
        ]);

        $this->assertTrue($asiento->exists());
        $this->assertEquals(250, $asiento->importe);

        $partidas = $asiento->getLines();
        $this->assertCount(2, $partidas);

        $this->assertEquals('4751000000', $partidas[0]->codsubcuenta);
        $this->assertEquals('Pago retención IRPF modelo 111', $partidas[0]->concepto);
        $this->assertEquals(250, $partidas[0]->debe);
        $this->assertEquals(0, $partidas[0]->haber);

        $this->assertEquals('5720000000', $partidas[1]->codsubcuenta);
        $this->assertEquals('Pago retención IRPF modelo 111', $partidas[1]->concepto);
        $this->assertEquals(0, $partidas[1]->debe);
        $this->assertEquals(250, $partidas[1]->haber);

        $asiento->delete();
    }

    public function testAsientoPredefinidoPagoModelo115(): void
    {
        $empresa = Empresas::default();

        $asientoPredefinido = new AsientoPredefinido();
        $this->assertTrue($asientoPredefinido->load(7));

        $asiento = $asientoPredefinido->generate([
            'idempresa' => $empresa->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
            'var_A' => 0,
            'var_B' => 80,
            'var_C' => 0,
        ]);

        $this->assertTrue($asiento->exists());
        $this->assertEquals(80, $asiento->importe);

        $partidas = $asiento->getLines();
        $this->assertCount(2, $partidas);

        $this->assertEquals('4751000000', $partidas[0]->codsubcuenta);
        $this->assertEquals('Pago retención alquiler modelo 115', $partidas[0]->concepto);
        $this->assertEquals(80, $partidas[0]->debe);
        $this->assertEquals(0, $partidas[0]->haber);

        $this->assertEquals('5720000000', $partidas[1]->codsubcuenta);
        $this->assertEquals('Pago retención alquiler modelo 115', $partidas[1]->concepto);
        $this->assertEquals(0, $partidas[1]->debe);
        $this->assertEquals(80, $partidas[1]->haber);

        $asiento->delete();
    }

    public function testAsientoPredefinidoPagoModelo130(): void
    {
        $empresa = Empresas::default();

        $asientoPredefinido = new AsientoPredefinido();
        $this->assertTrue($asientoPredefinido->load(8));

        $asiento = $asientoPredefinido->generate([
            'idempresa' => $empresa->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
            'var_A' => 0,
            'var_B' => 450,
            'var_C' => 0,
        ]);

        $this->assertTrue($asiento->exists());
        $this->assertEquals(450, $asiento->importe);

        $partidas = $asiento->getLines();
        $this->assertCount(2, $partidas);

        $this->assertEquals('4730000000', $partidas[0]->codsubcuenta);
        $this->assertEquals('Pago fraccionado IRPF modelo 130', $partidas[0]->concepto);
        $this->assertEquals(450, $partidas[0]->debe);
        $this->assertEquals(0, $partidas[0]->haber);

        $this->assertEquals('5720000000', $partidas[1]->codsubcuenta);
        $this->assertEquals('Pago fraccionado IRPF modelo 130', $partidas[1]->concepto);
        $this->assertEquals(0, $partidas[1]->debe);
        $this->assertEquals(450, $partidas[1]->haber);

        $asiento->delete();
    }

    public function testAsientoPredefinidoPagoSeguridadSocial(): void
    {
        $empresa = Empresas::default();

        $asientoPredefinido = new AsientoPredefinido();
        $this->assertTrue($asientoPredefinido->load(9));

        $asiento = $asientoPredefinido->generate([
            'idempresa' => $empresa->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
            'var_A' => 0,
            'var_B' => 800,
            'var_C' => 0,
        ]);

        $this->assertTrue($asiento->exists());
        $this->assertEquals(800, $asiento->importe);

        $textoMes = Tools::lang()->trans(strtolower(date('F', strtotime($asiento->fecha))));
        $this->assertEquals('Pago SS ' . $textoMes, $asiento->concepto);

        $partidas = $asiento->getLines();
        $this->assertCount(2, $partidas);

        $totalDebe = 0.0;
        $totalHaber = 0.0;
        foreach ($partidas as $partida) {
            $totalDebe += (float)$partida->debe;
            $totalHaber += (float)$partida->haber;
        }
        $this->assertEquals($totalDebe, $totalHaber);

        $this->assertEquals('4760000000', $partidas[0]->codsubcuenta);
        $this->assertEquals(800, $partidas[0]->debe);
        $this->assertEquals(0, $partidas[0]->haber);

        $this->assertEquals('5720000000', $partidas[1]->codsubcuenta);
        $this->assertEquals(0, $partidas[1]->debe);
        $this->assertEquals(800, $partidas[1]->haber);

        $asiento->delete();
    }

    public function testAsientoPredefinidoRegularizacionIva(): void
    {
        $empresa = Empresas::default();

        $asientoPredefinido = new AsientoPredefinido();
        $this->assertTrue($asientoPredefinido->load(10));

        // R = 1000 repercutido, S = 300 soportado → Z = 700 a ingresar
        $asiento = $asientoPredefinido->generate([
            'idempresa' => $empresa->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
            'var_A' => 0,
            'var_R' => 1000,
            'var_S' => 300,
        ]);

        $this->assertTrue($asiento->exists());
        $this->assertEquals(1000, $asiento->importe);

        $partidas = $asiento->getLines();
        $this->assertCount(3, $partidas);

        $totalDebe = 0.0;
        $totalHaber = 0.0;
        foreach ($partidas as $partida) {
            $totalDebe += (float)$partida->debe;
            $totalHaber += (float)$partida->haber;
        }
        $this->assertEquals($totalDebe, $totalHaber);

        $this->assertEquals('4770000000', $partidas[0]->codsubcuenta);
        $this->assertEquals(1000, $partidas[0]->debe);
        $this->assertEquals(0, $partidas[0]->haber);

        $this->assertEquals('4720000000', $partidas[1]->codsubcuenta);
        $this->assertEquals(0, $partidas[1]->debe);
        $this->assertEquals(300, $partidas[1]->haber);

        $this->assertEquals('4750000000', $partidas[2]->codsubcuenta);
        $this->assertEquals(0, $partidas[2]->debe);
        $this->assertEquals(700, $partidas[2]->haber);

        $asiento->delete();
    }

    public function testAsientoPredefinidoAlquilerConRetencion(): void
    {
        $empresa = Empresas::default();

        $asientoPredefinido = new AsientoPredefinido();
        $this->assertTrue($asientoPredefinido->load(11));

        // B=500, I=105 (21%), R=95 (19%) → Z = 500+105-95 = 510
        $asiento = $asientoPredefinido->generate([
            'idempresa' => $empresa->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
            'var_A' => 0,
            'var_B' => 500,
            'var_I' => 105,
            'var_R' => 95,
        ]);

        $this->assertTrue($asiento->exists());
        $this->assertEquals(605, $asiento->importe);

        $partidas = $asiento->getLines();
        $this->assertCount(4, $partidas);

        $totalDebe = 0.0;
        $totalHaber = 0.0;
        foreach ($partidas as $partida) {
            $totalDebe += (float)$partida->debe;
            $totalHaber += (float)$partida->haber;
        }
        $this->assertEquals($totalDebe, $totalHaber);

        $this->assertEquals('6210000000', $partidas[0]->codsubcuenta);
        $this->assertEquals(500, $partidas[0]->debe);
        $this->assertEquals(0, $partidas[0]->haber);

        $this->assertEquals('4720000000', $partidas[1]->codsubcuenta);
        $this->assertEquals(105, $partidas[1]->debe);
        $this->assertEquals(0, $partidas[1]->haber);

        $this->assertEquals('4751000000', $partidas[2]->codsubcuenta);
        $this->assertEquals(0, $partidas[2]->debe);
        $this->assertEquals(95, $partidas[2]->haber);

        $this->assertEquals('4100000000', $partidas[3]->codsubcuenta);
        $this->assertEquals(0, $partidas[3]->debe);
        $this->assertEquals(510, $partidas[3]->haber);

        $asiento->delete();
    }

    public function testAsientoPredefinidoPagoAlquilerConRetencion(): void
    {
        $empresa = Empresas::default();

        $asientoPredefinido = new AsientoPredefinido();
        $this->assertTrue($asientoPredefinido->load(12));

        $asiento = $asientoPredefinido->generate([
            'idempresa' => $empresa->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
            'var_A' => 0,
            'var_B' => 0,
            'var_I' => 510,
        ]);

        $this->assertTrue($asiento->exists());
        $this->assertEquals(510, $asiento->importe);

        $textoMes = Tools::lang()->trans(strtolower(date('F', strtotime($asiento->fecha))));
        $this->assertEquals('Pago alquiler ' . $textoMes, $asiento->concepto);

        $partidas = $asiento->getLines();
        $this->assertCount(2, $partidas);

        $this->assertEquals('4100000000', $partidas[0]->codsubcuenta);
        $this->assertEquals('Pago alquiler ' . $textoMes, $partidas[0]->concepto);
        $this->assertEquals(510, $partidas[0]->debe);
        $this->assertEquals(0, $partidas[0]->haber);

        $this->assertEquals('5720000000', $partidas[1]->codsubcuenta);
        $this->assertEquals('Pago alquiler ' . $textoMes, $partidas[1]->concepto);
        $this->assertEquals(0, $partidas[1]->debe);
        $this->assertEquals(510, $partidas[1]->haber);

        $asiento->delete();
    }

    public function testAsientoPredefinidoPagoImpuestoSociedades(): void
    {
        $empresa = Empresas::default();

        $asientoPredefinido = new AsientoPredefinido();
        $this->assertTrue($asientoPredefinido->load(13));

        $asiento = $asientoPredefinido->generate([
            'idempresa' => $empresa->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
            'var_A' => 0,
            'var_B' => 1200,
            'var_C' => 0,
        ]);

        $this->assertTrue($asiento->exists());
        $this->assertEquals(1200, $asiento->importe);
        $this->assertEquals('Pago impuesto sociedades ' . date('Y', strtotime($asiento->fecha)), $asiento->concepto);

        $partidas = $asiento->getLines();
        $this->assertCount(2, $partidas);

        $this->assertEquals('4752000000', $partidas[0]->codsubcuenta);
        $this->assertEquals('Pago impuesto sociedades', $partidas[0]->concepto);
        $this->assertEquals(1200, $partidas[0]->debe);
        $this->assertEquals(0, $partidas[0]->haber);

        $this->assertEquals('5720000000', $partidas[1]->codsubcuenta);
        $this->assertEquals('Pago impuesto sociedades', $partidas[1]->concepto);
        $this->assertEquals(0, $partidas[1]->debe);
        $this->assertEquals(1200, $partidas[1]->haber);

        $asiento->delete();
    }

    public function testAsientoPredefinidoPagoComisionesBancarias(): void
    {
        $empresa = Empresas::default();

        $asientoPredefinido = new AsientoPredefinido();
        $this->assertTrue($asientoPredefinido->load(14));

        $asiento = $asientoPredefinido->generate([
            'idempresa' => $empresa->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
            'var_A' => 0,
            'var_B' => 18.50,
            'var_C' => 0,
        ]);

        $this->assertTrue($asiento->exists());
        $this->assertEquals(18.50, $asiento->importe);

        $textoMes = Tools::lang()->trans(strtolower(date('F', strtotime($asiento->fecha))));
        $this->assertEquals('Comisiones bancarias ' . $textoMes, $asiento->concepto);

        $partidas = $asiento->getLines();
        $this->assertCount(2, $partidas);

        $this->assertEquals('6260000000', $partidas[0]->codsubcuenta);
        $this->assertEquals('Comisiones bancarias ' . $textoMes, $partidas[0]->concepto);
        $this->assertEquals(18.50, $partidas[0]->debe);
        $this->assertEquals(0, $partidas[0]->haber);

        $this->assertEquals('5720000000', $partidas[1]->codsubcuenta);
        $this->assertEquals('Comisiones bancarias ' . $textoMes, $partidas[1]->concepto);
        $this->assertEquals(0, $partidas[1]->debe);
        $this->assertEquals(18.50, $partidas[1]->haber);

        $asiento->delete();
    }

    public function testAsientoPredefinidoConConceptoPersonalizado(): void
    {
        // obtenemos la empresa predefinida
        $empresa = Empresas::default();

        // cargamos el asiento predefinido de nómina
        $asientoPredefinido = new AsientoPredefinido();
        $this->assertTrue($asientoPredefinido->load(1));

        // generamos el asiento pasando un concepto personalizado
        $conceptoPersonalizado = 'Concepto personalizado de prueba';
        $asiento = $asientoPredefinido->generate([
            'idempresa' => $empresa->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
            'concepto' => $conceptoPersonalizado,
            'var_A' => 0,
            'var_B' => 0,
            'var_S' => 500,
            'var_L' => 1200,
            'var_R' => 200,
            'var_D' => 1500,
        ]);

        // comprobamos que el asiento se ha creado correctamente
        $this->assertTrue($asiento->exists());

        // comprobamos que el concepto del asiento es el personalizado y no el del predefinido
        $this->assertEquals($conceptoPersonalizado, $asiento->concepto);

        // borramos el asiento
        $asiento->delete();
    }

    /**
     * Un saldo residual en coma flotante (5.55e-17) no debe romper la fórmula de Z
     * ni mezclarse con la variable E del formulario.
     */
    public function testSaldoZConNotacionCientifica(): void
    {
        // plantilla con variables A, B, C y E; la línea 4 usa Z (0.1 + 0.2 - 0.3 = 5.55e-17)
        $predefinido = $this->crearPredefinido('Test saldo Z', ['A', 'B', 'C', 'E'], [
            ['5700000000', 'A', '0'],
            ['5700000000', 'B', '0'],
            ['5720000000', '0', 'C'],
            ['5700000000', 'Z', '0'],
        ]);

        // generamos el asiento
        $asiento = $predefinido->generate([
            'idempresa' => Empresas::default()->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
            'var_A' => 0.1,
            'var_B' => 0.2,
            'var_C' => 0.3,
            'var_E' => 4.14,
        ]);
        $this->asientos[] = $asiento;

        // el asiento se crea con 4 partidas y queda cuadrado; la que usa Z vale 0
        $this->assertTrue($asiento->exists());
        $partidas = $asiento->getLines();
        $this->assertCount(4, $partidas);
        $this->assertEqualsWithDelta(0.3, $asiento->importe, 0.0001);
        $this->assertEqualsWithDelta(0.3, array_sum(array_column($partidas, 'debe')), 0.0001);
        $this->assertEqualsWithDelta(0.3, array_sum(array_column($partidas, 'haber')), 0.0001);
    }

    /**
     * Una fórmula que no se puede evaluar debe avisar y no lanzar una excepción.
     */
    public function testFormulaNoEvaluable(): void
    {
        // en el debe, una división entre cero que solo falla al generar con los valores reales
        $predefinido = $this->crearPredefinido('Test fórmula no evaluable en debe', ['A', 'B'], [
            ['5700000000', 'A/B', '0'],
            ['5720000000', '0', 'A'],
        ]);
        $this->assertGeneracionCancelada($predefinido, ['var_A' => 10, 'var_B' => 0], 'A/B');

        // en el haber, una fórmula mal escrita guardada antes de que se validara al guardar la línea
        $predefinido = $this->crearPredefinido('Test fórmula antigua inválida en haber', ['A'], [
            ['5700000000', '0', '0'],
            ['5720000000', '0', 'A'],
        ]);
        $sql = 'UPDATE ' . AsientoPredefinidoLinea::tableName() . " SET haber = 'A/'"
            . ' WHERE id = ' . (int)$predefinido->getLines()[0]->id;
        $this->assertTrue((new DataBase())->exec($sql));
        $this->assertGeneracionCancelada($predefinido, ['var_A' => 10], 'A/');
    }

    /**
     * Los importes grandes se calculan sin perder precisión al pasarlos a la fórmula.
     */
    public function testFormulaConImportesGrandes(): void
    {
        $predefinido = $this->crearPredefinido('Test importes grandes', ['A', 'B'], [
            ['5700000000', 'A+B', '0'],
            ['5720000000', '0', 'Z'],
        ]);

        $asiento = $predefinido->generate([
            'idempresa' => Empresas::default()->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
            'var_A' => '1234567.89',
            'var_B' => '0.01',
        ]);
        $this->asientos[] = $asiento;

        $this->assertTrue($asiento->exists());
        $this->assertEqualsWithDelta(1234567.9, $asiento->importe, 0.001);
        foreach ($asiento->getLines() as $partida) {
            $this->assertEqualsWithDelta(1234567.9, $partida->debe + $partida->haber, 0.001);
        }
    }

    /**
     * Restaurar las plantillas incluidas no debe tocar las del usuario, aunque ocupen un id del CSV
     * (como en instalaciones anteriores a la 3.6), ni duplicar plantillas al repetirlo.
     */
    public function testRestaurarNoSobrescribePlantillasDelUsuario(): void
    {
        $plantillasCsv = $this->leerCsv('asientospre.csv');
        $ultima = end($plantillasCsv);

        // dejamos libre el id de la última plantilla incluida y creamos con ese id una plantilla del usuario
        $existente = new AsientoPredefinido();
        if ($existente->load($ultima['id'])) {
            $this->assertTrue($existente->delete());
        }
        $propia = new AsientoPredefinido();
        $propia->id = (int)$ultima['id'];
        $propia->descripcion = 'Plantilla propia del usuario';
        $propia->concepto = 'Concepto propio';
        $this->assertTrue($propia->save());
        $this->predefinidos[] = $propia;
        $linea = new AsientoPredefinidoLinea();
        $linea->idasientopre = $propia->id;
        $linea->codsubcuenta = '5700000000';
        $linea->codcontrapartida = '';
        $linea->concepto = 'Línea propia';
        $this->assertTrue($linea->save());

        // restauramos dos veces
        $this->assertTrue(AsientoPredefinidoRestorer::restore('ESP'));
        $this->assertTrue(AsientoPredefinidoRestorer::restore('ESP'));

        // la plantilla del usuario sigue igual, con su línea
        $this->assertTrue($propia->load($propia->id));
        $this->assertSame('Plantilla propia del usuario', $propia->descripcion);
        $this->assertSame('Concepto propio', $propia->concepto);
        $this->assertCount(1, $propia->getLines());

        // cada plantilla incluida existe una sola vez y con sus líneas y variables del CSV
        $lineasCsv = array_count_values(array_column($this->leerCsv('asientospre_lineas.csv'), 'idasientopre'));
        $variablesCsv = array_count_values(array_column($this->leerCsv('asientospre_variables.csv'), 'idasientopre'));
        foreach ($plantillasCsv as $plantilla) {
            $where = [Where::eq('descripcion', $plantilla['descripcion'])];
            $this->assertSame(1, AsientoPredefinido::count($where), $plantilla['descripcion']);

            $predefinido = new AsientoPredefinido();
            $this->assertTrue($predefinido->loadWhere($where));
            $this->assertCount($lineasCsv[$plantilla['id']] ?? 0, $predefinido->getLines(), $plantilla['descripcion']);
            $this->assertCount($variablesCsv[$plantilla['id']] ?? 0, $predefinido->getVariables(), $plantilla['descripcion']);
        }

        // después de restaurar se pueden crear plantillas, líneas y variables (secuencias al día en PostgreSQL)
        $this->crearPredefinido('Test tras restaurar', ['A'], [
            ['5700000000', 'A', '0'],
            ['5720000000', '0', 'A'],
        ]);
    }

    /**
     * Una plantilla sin líneas no genera un asiento vacío.
     */
    public function testPlantillaSinLineas(): void
    {
        $predefinido = $this->crearPredefinido('Test sin líneas', [], []);

        MiniLog::clear();
        $asiento = $predefinido->generate([
            'idempresa' => Empresas::default()->idempresa,
            'fecha' => Tools::date(),
        ]);

        $this->assertFalse($asiento->exists());
        $this->assertContains('predefined-without-lines', $this->clavesAvisadas());
    }

    /**
     * Si el asiento generado no cuadra se avisa, pero se crea para poder completarlo a mano.
     */
    public function testAsientoDescuadrado(): void
    {
        $predefinido = $this->crearPredefinido('Test descuadrado', ['A', 'B'], [
            ['5700000000', 'A', '0'],
            ['5720000000', '0', 'B'],
        ]);

        MiniLog::clear();
        $asiento = $predefinido->generate([
            'idempresa' => Empresas::default()->idempresa,
            'fecha' => Tools::date(),
            'var_A' => 10,
            'var_B' => 5,
        ]);
        $this->asientos[] = $asiento;

        $this->assertTrue($asiento->exists());
        $this->assertContains('predefined-unbalanced-entry', $this->clavesAvisadas());
    }

    /**
     * Un debe o haber "Z" cuadra el asiento esté en la línea que esté, como en las plantillas
     * que ponen primero la línea del cliente (430) y después las de ingreso e IVA.
     */
    public function testZCuadraElAsientoEnCualquierLinea(): void
    {
        $casos = [
            'Z en el debe de la primera línea' => [
                ['4300000000', 'Z', '0'],
                ['7000000000', '0', 'B'],
                ['4770000000', '0', 'C'],
            ],
            'Z en el debe de la última línea' => [
                ['7000000000', '0', 'B'],
                ['4770000000', '0', 'C'],
                ['4300000000', 'Z', '0'],
            ],
            'Z en el haber de la primera línea' => [
                ['4000000000', '0', 'Z'],
                ['6000000000', 'B', '0'],
                ['4720000000', 'C', '0'],
            ],
        ];
        foreach ($casos as $caso => $lineas) {
            $predefinido = $this->crearPredefinido('Test ' . $caso, ['B', 'C'], $lineas);

            MiniLog::clear();
            $asiento = $predefinido->generate([
                'idempresa' => Empresas::default()->idempresa,
                'fecha' => Tools::date(),
                'var_B' => 1000,
                'var_C' => 210,
            ]);
            $this->asientos[] = $asiento;

            $this->assertTrue($asiento->exists(), $caso);
            $this->assertNotContains('predefined-unbalanced-entry', $this->clavesAvisadas(), $caso);
            $this->assertEqualsWithDelta(1210, $asiento->importe, 0.001, $caso);
            foreach ($asiento->getLines() as $partida) {
                $this->assertGreaterThanOrEqual(0, $partida->debe, $caso);
                $this->assertGreaterThanOrEqual(0, $partida->haber, $caso);
            }
        }
    }

    /**
     * Los errores de subcuenta se avisan con claves de traducción e indican la subcuenta.
     */
    public function testAvisosDeSubcuenta(): void
    {
        $casos = [
            '57-00' => 'predefined-subaccount-invalid-chars',
            '57Z' => 'predefined-subaccount-z-variable',
            '57AB' => 'predefined-subaccount-multiple-variables',
            '57.0.1' => 'predefined-subaccount-multiple-dots',
        ];
        foreach ($casos as $codsubcuenta => $clave) {
            $linea = new AsientoPredefinidoLinea();
            $linea->idasientopre = 1;
            $linea->codsubcuenta = $codsubcuenta;
            $linea->codcontrapartida = '';

            MiniLog::clear();
            $this->assertFalse($linea->test(), $codsubcuenta);
            $this->assertSame([$clave], $this->clavesAvisadas(), $codsubcuenta);
            $this->assertSame($codsubcuenta, MiniLog::read('', ['warning'])[0]['context']['%subaccount%']);
        }
    }

    public function testMethodTest(): void
    {
        $asientoPredefinido = new AsientoPredefinido();
        $asientoPredefinido->descripcion = null;
        $asientoPredefinido->concepto = null;
        $this->assertTrue($asientoPredefinido->test());
    }

    /**
     * Al guardar una línea se rechazan las fórmulas que no se pueden interpretar, avisando de cuál es.
     */
    /**
     * Por la API la contrapartida, la subcuenta, el debe o el haber pueden llegar vacíos (null):
     * la línea se valida sin lanzar un TypeError (informe de fallo 8723).
     */
    public function testLineaConCamposNulos(): void
    {
        $linea = new AsientoPredefinidoLinea();
        $linea->idasientopre = 1;
        $linea->codsubcuenta = '5700000000';
        $linea->codcontrapartida = null;
        $linea->debe = null;
        $linea->haber = null;
        $this->assertTrue($linea->test());
        $this->assertSame('0', $linea->debe);
        $this->assertSame('0', $linea->haber);

        // sin subcuenta no es válida, pero tampoco lanza un TypeError
        $linea->codsubcuenta = null;
        $this->assertFalse($linea->test());
    }

    /**
     * Los decimales escritos sin el cero (.21) se aceptan al guardar y al generar.
     */
    public function testDecimalesSinCero(): void
    {
        // al guardar la línea se añade el cero
        $linea = new AsientoPredefinidoLinea();
        $linea->idasientopre = 1;
        $linea->codsubcuenta = '5700000000';
        $linea->codcontrapartida = '';
        $linea->debe = 'a*20*.21';
        $linea->haber = '-.5+1';
        $this->assertTrue($linea->test());
        $this->assertSame('A*20*0.21', $linea->debe);
        $this->assertSame('-0.5+1', $linea->haber);

        // una fórmula guardada antes de validarse también se calcula al generar
        $predefinido = $this->crearPredefinido('Test decimales sin cero', ['A'], [
            ['5700000000', '0', '0'],
            ['5720000000', '0', 'Z'],
        ]);
        $sql = 'UPDATE ' . AsientoPredefinidoLinea::tableName() . " SET debe = 'A*20*.21'"
            . ' WHERE id = ' . (int)$predefinido->getLines()[0]->id;
        $this->assertTrue((new DataBase())->exec($sql));

        $asiento = $predefinido->generate([
            'idempresa' => Empresas::default()->idempresa,
            'fecha' => Tools::date(),
            'var_A' => 3,
        ]);
        $this->asientos[] = $asiento;

        $this->assertTrue($asiento->exists());
        $this->assertEqualsWithDelta(12.6, $asiento->importe, 0.001);
    }

    public function testLineaFormulaValidaEInvalida(): void
    {
        $linea = new AsientoPredefinidoLinea();
        $linea->idasientopre = 1;
        $linea->codsubcuenta = '6000000000';
        $linea->codcontrapartida = '';

        // fórmulas válidas
        foreach (['N*20*1.21', '-5', 'A', 'A + B / 2', ''] as $formula) {
            $linea->debe = $formula;
            $linea->haber = '0';
            MiniLog::clear();
            $this->assertTrue($linea->test(), 'fórmula válida: ' . $formula);
            $this->assertSame([], $this->formulasAvisadas('%subaccount%'), 'fórmula válida: ' . $formula);
        }

        // fórmulas inválidas en el debe y en el haber: punto final de más, operador sin segundo
        // operando y variables pegadas a un número o a otra variable
        foreach (['N*20*1.21.', 'A+', '2A', 'AB'] as $formula) {
            foreach (['debe', 'haber'] as $columna) {
                $linea->debe = $columna === 'debe' ? $formula : '0';
                $linea->haber = $columna === 'haber' ? $formula : '0';
                MiniLog::clear();
                $this->assertFalse($linea->test(), $formula . ' en el ' . $columna);
                $this->assertSame([$formula], $this->formulasAvisadas('%subaccount%'), $formula . ' en el ' . $columna);
            }
        }
    }

    /**
     * Crea un asiento predefinido de prueba con sus variables y líneas.
     *
     * @param string $descripcion descripción de la plantilla
     * @param string[] $variables códigos de las variables
     * @param array $lineas líneas como [codsubcuenta, debe, haber]
     *
     * @return AsientoPredefinido
     */
    protected function crearPredefinido(string $descripcion, array $variables, array $lineas): AsientoPredefinido
    {
        $predefinido = new AsientoPredefinido();
        $predefinido->descripcion = $descripcion;
        $predefinido->concepto = $descripcion;
        $this->assertTrue($predefinido->save());
        $this->predefinidos[] = $predefinido;

        foreach ($variables as $codigo) {
            $variable = new AsientoPredefinidoVariable();
            $variable->idasientopre = $predefinido->id;
            $variable->codigo = $codigo;
            $variable->mensaje = 'Variable ' . $codigo;
            $this->assertTrue($variable->save());
        }

        foreach ($lineas as $orden => [$codsubcuenta, $debe, $haber]) {
            $linea = new AsientoPredefinidoLinea();
            $linea->idasientopre = $predefinido->id;
            $linea->codsubcuenta = $codsubcuenta;
            $linea->codcontrapartida = '';
            $linea->concepto = 'Línea ' . ($orden + 1);
            $linea->debe = $debe;
            $linea->haber = $haber;
            $linea->orden = $orden;
            $this->assertTrue($linea->save());
        }

        return $predefinido;
    }

    /**
     * Genera el asiento y comprueba que no se crea y que se avisa solo de la fórmula que falla.
     *
     * @param AsientoPredefinido $predefinido
     * @param array $valores valores de las variables (var_X)
     * @param string $formula fórmula de la que se debe avisar
     */
    protected function assertGeneracionCancelada(AsientoPredefinido $predefinido, array $valores, string $formula): void
    {
        MiniLog::clear();
        $asiento = $predefinido->generate(array_merge([
            'idempresa' => Empresas::default()->idempresa,
            'fecha' => Tools::date(),
            'canal' => 0,
        ], $valores));

        $this->assertFalse($asiento->exists(), $formula);
        $this->assertSame([$formula], $this->formulasAvisadas('%error%'), $formula);
    }

    /**
     * Devuelve las claves (sin traducir) de los avisos registrados.
     *
     * @return string[]
     */
    protected function clavesAvisadas(): array
    {
        return array_column(MiniLog::read('', ['warning']), 'original');
    }

    /**
     * Lee un CSV de las plantillas incluidas para España.
     *
     * @param string $fichero
     *
     * @return array[]
     */
    protected function leerCsv(string $fichero): array
    {
        $handle = fopen(Tools::folder('Plugins', 'AsientosPredefinidos', 'Data', 'Codpais', 'ESP', $fichero), 'rb');
        $this->assertNotFalse($handle, $fichero);

        $filas = [];
        $cabecera = fgetcsv($handle, 0, ',', '"', '\\');
        while (false !== ($fila = fgetcsv($handle, 0, ',', '"', '\\'))) {
            $filas[] = array_combine($cabecera, $fila);
        }
        fclose($handle);

        return $filas;
    }

    /**
     * Devuelve las fórmulas de los avisos de fórmula inválida registrados, sin depender del idioma.
     * Los avisos se distinguen por su contexto: '%error%' para los de la generación
     * (invalid-formula) y '%subaccount%' para los de guardar la línea (predefined-line-invalid-formula).
     *
     * @param string $claveContexto
     *
     * @return string[]
     */
    protected function formulasAvisadas(string $claveContexto): array
    {
        $formulas = [];
        foreach (MiniLog::read('', ['warning']) as $aviso) {
            if (isset($aviso['context']['%formula%'], $aviso['context'][$claveContexto])) {
                $formulas[] = $aviso['context']['%formula%'];
            }
        }
        return $formulas;
    }

    protected function tearDown(): void
    {
        // borramos lo creado en el test, cada elemento por separado para no interferir con el resto
        foreach ($this->asientos as $asiento) {
            try {
                $asiento->delete();
            } catch (\Throwable $exception) {
                // ignoramos errores de limpieza
            }
        }
        foreach ($this->predefinidos as $predefinido) {
            try {
                foreach ($predefinido->getLines() as $linea) {
                    $linea->delete();
                }
                foreach ($predefinido->getVariables() as $variable) {
                    $variable->delete();
                }
                $predefinido->delete();
            } catch (\Throwable $exception) {
                // ignoramos errores de limpieza
            }
        }
        $this->asientos = [];
        $this->predefinidos = [];

        $this->logErrors();
    }
}
