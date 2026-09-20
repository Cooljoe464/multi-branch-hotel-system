<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ImportTemplateController extends Controller
{
    private const TEMPLATES = [
        'rooms' => [
            'headers' => ['number', 'floor', 'wing', 'room_type_code', 'room_type_name', 'status', 'is_accessible', 'is_smoking', 'base_rate', 'max_occupancy', 'bed_count', 'bed_type'],
            'example' => ['101', '1', 'Main', 'STD', 'Standard', 'available', 'no', 'no', 150, 2, 1, 'queen'],
        ],
        'guests' => [
            'headers' => ['first_name', 'last_name', 'email', 'phone', 'date_of_birth', 'nationality', 'id_type', 'id_number', 'company', 'vip_status', 'total_stays', 'total_nights', 'total_spent'],
            'example' => ['John', 'Doe', 'john@example.com', '+1234567890', '1990-01-15', 'US', 'passport', 'AB123456', 'Acme Corp', 'gold', 5, 23, 4500],
        ],
        'reservations' => [
            'headers' => ['guest_name', 'guest_email', 'guest_phone', 'room_number', 'room_type_code', 'status', 'source', 'check_in_date', 'check_out_date', 'room_rate', 'total_amount', 'amount_paid', 'adults', 'children', 'payment_status', 'special_requests'],
            'example' => ['John Doe', 'john@example.com', '+1234567890', '101', 'STD', 'confirmed', 'booking_com', '2026-10-01', '2026-10-05', 150, 600, 600, 2, 0, 'paid', 'Late check-in'],
        ],
    ];

    public function download(string $type): Response
    {
        if (! isset(self::TEMPLATES[$type])) {
            abort(404);
        }

        $template = self::TEMPLATES[$type];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->fromArray($template['headers'], null, 'A1');
        $sheet->fromArray($template['example'], null, 'A2');

        $writer = new Xlsx($spreadsheet);

        $tempFile = tempnam(sys_get_temp_dir(), 'import_template_');
        $writer->save($tempFile);

        $contents = file_get_contents($tempFile);
        @unlink($tempFile);

        if ($contents === false) {
            abort(500, 'Failed to read generated template file.');
        }

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$type}_import_template.xlsx\"",
        ]);
    }
}
