<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Import de courses depuis un fichier CSV ou Excel.
 */
class OrderImportTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Sanctum::actingAs($this->merchantUser);
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('courses.csv', $content);
    }

    public function test_template_can_be_downloaded_and_imported_as_is(): void
    {
        $template = $this->get('/api/v1/orders/import/template')->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->getContent();

        $this->assertStringContainsString('Téléphone;', $template);

        $this->post('/api/v1/orders/import', ['file' => $this->csv($template), 'dry_run' => 1])
            ->assertOk()
            ->assertJsonPath('data.valid', 2)
            ->assertJsonPath('data.invalid', 0)
            ->assertJsonPath('data.rows.0.zone_name', 'Yopougon')
            ->assertJsonPath('data.rows.0.delivery_fee', 1500)
            ->assertJsonPath('data.rows.0.cod_amount', 16500)
            ->assertJsonPath('data.rows.1.cod_amount', 0);
        $this->assertSame(0, Order::count());
    }

    public function test_rows_are_checked_then_created(): void
    {
        $content = "Nom,Tel,Commune,Quartier,Montant,Frais payés par,Référence,Date\n"
            ."Awa,0708091011,yopougon,,\"12 500 F\",client,CMD-1,\n"
            .'Koffi,708091012,Cocody,Angré,15k,moi,CMD-2,'.today()->addDay()->format('d/m/Y')."\n"
            ."Inconnu,12,Bouaké,,abc,,CMD-3,31/02/2026\n"
            .",,,,,,,\n";

        $preview = $this->post('/api/v1/orders/import', ['file' => $this->csv($content), 'dry_run' => 1])->assertOk();
        $preview->assertJsonPath('data.valid', 2)->assertJsonPath('data.invalid', 1);
        $rows = $preview->json('data.rows');

        $this->assertSame(2, $rows[0]['line']);
        $this->assertSame('+2250708091011', $rows[0]['data']['recipient_phone']);
        $this->assertSame(12500, $rows[0]['data']['items_amount']);
        $this->assertSame('+2250708091012', $rows[1]['data']['recipient_phone'], 'Le 0 initial retiré par Excel est rétabli');
        $this->assertSame('Cocody › Angré', $rows[1]['zone_name']);
        $this->assertSame(15000, $rows[1]['data']['items_amount']);
        $this->assertSame('merchant', $rows[1]['data']['fee_payer']);
        $this->assertCount(4, $rows[2]['errors']);

        // Avec une ligne en erreur, rien n'est créé sans accord explicite
        $this->post('/api/v1/orders/import', ['file' => $this->csv($content)])->assertJsonValidationErrors('file');
        $this->assertSame(0, Order::count());

        $this->post('/api/v1/orders/import', ['file' => $this->csv($content), 'skip_invalid' => 1])
            ->assertCreated()
            ->assertJsonCount(2, 'data.created')
            ->assertJsonPath('data.created.1.line', 3);

        $orders = Order::orderBy('id')->get();
        $this->assertSame(['import', 'import'], $orders->pluck('source')->all());
        $this->assertSame(['CMD-1', 'CMD-2'], $orders->pluck('merchant_reference')->all());
        $this->assertSame(14000, $orders[0]->cod_amount);
        $this->assertSame(today()->addDay()->toDateString(), $orders[1]->delivery_scheduled_date->toDateString());
    }

    public function test_excel_files_are_read(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Nom', 'Téléphone', 'Commune', 'Montant']));
        $writer->addRow(Row::fromValues(['Mariam', 707070707, 'Yopougon', 8000]));
        $writer->close();

        $file = new UploadedFile($path, 'courses.xlsx', null, null, true);
        $this->post('/api/v1/orders/import', ['file' => $file])->assertCreated()->assertJsonCount(1, 'data.created');
        $this->assertSame('+2250707070707', Order::first()->recipient_phone);
        $this->assertSame(8000, Order::first()->items_amount);
    }

    public function test_bad_files_and_staff_imports(): void
    {
        $this->post('/api/v1/orders/import', ['file' => $this->csv("Nom;Adresse\nAwa;Siporex\n"), 'dry_run' => 1])
            ->assertJsonValidationErrors('file');
        $this->post('/api/v1/orders/import', ['file' => $this->csv("Téléphone;Commune\n"), 'dry_run' => 1])
            ->assertJsonValidationErrors('file');
        $this->post('/api/v1/orders/import', ['file' => UploadedFile::fake()->create('courses.pdf', 10, 'application/pdf')])
            ->assertJsonValidationErrors('file');

        // Le personnel importe pour un marchand
        Sanctum::actingAs($this->dispatcher);
        $file = $this->csv("Téléphone;Commune;Montant\n0707070707;Yopougon;5000\n");
        $this->post('/api/v1/orders/import', ['file' => $file])->assertJsonValidationErrors('merchant_id');
        $this->post('/api/v1/orders/import', ['file' => $file, 'merchant_id' => $this->merchant->id])->assertCreated();
        $this->assertSame($this->merchant->id, Order::first()->merchant_id);
    }
}
