<?php

namespace App\Services;

use App\Http\Controllers\CheckOutController;
use App\Http\Controllers\EvacuationCenterController;
use App\Models\CenterFamilySnapshot;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Support\Format;
use App\Support\Plural;
use App\Support\XlsxWriter;
use Illuminate\Support\Str;

/**
 * Print and download (barangay officials): files built on this computer
 * from the data it holds, online or not. Every file says
 * "Generated offline, as of …. May not include records not yet synced."
 *
 *  - ec-board              EC Information Board of one center (PDF, Excel)
 *  - family-list           families at every center and in private houses (Excel)
 *  - relief-sheet          relief distribution sheet of one center, with signature lines (PDF)
 *  - families-to-evacuate  the list per purok (PDF, Excel)
 */
class Documents
{
    public const DOCUMENTS = [
        'ec-board' => ['label' => 'EC Information Board', 'formats' => ['pdf', 'xlsx'], 'per_center' => true],
        'family-list' => ['label' => 'Family list', 'formats' => ['xlsx'], 'per_center' => false],
        'relief-sheet' => ['label' => 'Relief distribution sheet', 'formats' => ['pdf'], 'per_center' => true],
        'families-to-evacuate' => ['label' => 'Families to evacuate per purok', 'formats' => ['pdf', 'xlsx'], 'per_center' => false],
    ];

    public const MIME = [
        'pdf' => 'application/pdf',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(private PdfRenderer $pdf) {}

    /** The line every generated file carries. */
    public static function offlineNote(?\DateTimeInterface $at = null): string
    {
        return 'Generated offline, as of '.Format::when($at ?? now()).'. May not include records not yet synced.';
    }

    /**
     * "EC-Information-Board_Bacong-Gym_Typhoon-Drill_2026-11-23-2105.pdf".
     * A practice event's files start like the server's: DEMO-not-for-submission_.
     */
    public static function fileName(string $document, string $format, EvacuationEvent $event, ?EvacuationCenter $center): string
    {
        $parts = array_filter([
            Str::slug(self::DOCUMENTS[$document]['label']),
            $center ? Str::slug($center->name) : null,
            Str::slug($event->name),
            now(Format::TIMEZONE)->format('Y-m-d-Hi'),
        ]);

        return ($event->is_demo ? 'DEMO-not-for-submission_' : '').implode('_', $parts).'.'.$format;
    }

    /** The page's data, shared by its PDF and its Excel file. */
    public function data(string $document, LocalAuth $auth, EvacuationEvent $event, ?EvacuationCenter $center): array
    {
        $base = ['auth' => $auth, 'event' => $event, 'center' => $center, 'note' => self::offlineNote(),
            'title' => self::DOCUMENTS[$document]['label'], 'barangay' => $auth->barangay_name];

        return $base + match ($document) {
            'ec-board' => app(EvacuationCenterController::class)->boardData($center, $event->id) + [
                'familiesHere' => CheckOutController::familiesHere($center, $event),
            ],
            'relief-sheet' => [
                'familiesHere' => CheckOutController::familiesHere($center, $event),
                'asOf' => CenterFamilySnapshot::for($center, $event)?->fetched_at,
            ],
            'family-list' => [
                'centers' => EvacuationCenter::where('barangay_remote_id', $auth->barangay_id)->where('status', '!=', 'closed')->orderBy('name')->get()
                    ->map(fn (EvacuationCenter $c) => ['center' => $c, 'families' => CheckOutController::familiesHere($c, $event),
                        'asOf' => CenterFamilySnapshot::for($c, $event)?->fetched_at]),
                'privateHouses' => app(PrivateHouses::class)->forEvent($event, (int) $auth->barangay_id),
            ],
            'families-to-evacuate' => ['list' => app(FamiliesToEvacuate::class)->forEvent($event, (int) $auth->barangay_id)],
        };
    }

    /** The self-contained page a PDF is made from (and shown to print outside the desktop app). */
    public function html(string $document, array $data): string
    {
        return view('print.'.$document, $data)->render();
    }

    /** @return string|null the PDF's bytes, or null where no PDF engine runs (show the page to print) */
    public function pdf(string $document, array $data): ?string
    {
        return $this->pdf->render($this->html($document, $data));
    }

    public function xlsx(string $document, array $data): string
    {
        $head = [
            [$data['title'].' · Barangay '.$data['barangay']],
            ['Event: '.$data['event']->name.($data['center'] ? ' · Center: '.$data['center']->name : '')],
            [$data['note']],
            ['This file contains personal information. Keep it secure.'],
            [],
        ];
        $xlsx = new XlsxWriter;

        if ($document === 'ec-board') {
            $snap = $data['sectoralSnapshot'];
            $rows = array_merge($head, [
                ['No. of families (cumulative / now)', $snap?->families_cumulative, $snap?->families_now],
                ['No. of persons (cumulative / now)', $snap?->persons_cumulative, $snap?->persons_now],
                ['4Ps beneficiary families', $snap?->beneficiaries_4ps],
                ['Server figures as of', $snap?->fetched_at ? Format::when($snap->fetched_at) : 'not fetched yet'],
                [],
                ['Age group', 'Male', 'Female', 'Total', 'Added on this computer, not yet synced'],
            ]);
            $bold = [0, 10];
            foreach ($data['breakdownAgeBrackets'] as $bracket => $label) {
                $known = $data['lastKnownBreakdown'][$bracket];
                $rows[] = [$label, $known['male'], $known['female'], $known['total'], $data['pendingBreakdown'][$bracket]['total'] ?: null];
            }
            $rows[] = ['Total', $data['lastKnownBreakdown']['total']['male'], $data['lastKnownBreakdown']['total']['female'], $data['lastKnownBreakdown']['total']['total'], $data['pendingBreakdown']['total']['total'] ?: null];
            $bold[] = count($rows) - 1;
            $rows[] = [];
            $rows[] = ['Sectoral group', 'Male', 'Female', 'Total', 'Added on this computer, not yet synced'];
            $bold[] = count($rows) - 1;
            $known = collect($snap?->sectoral_groups ?? [])->keyBy('sectoral_group');
            foreach ($data['sectoralGroups'] as $group => $label) {
                $m = (int) ($known[$group]['male_count'] ?? 0);
                $f = (int) ($known[$group]['female_count'] ?? 0);
                $pending = $data['pendingSectoral'][$group]['male'] + $data['pendingSectoral'][$group]['female'];
                $rows[] = [$label, $m, $f, $m + $f, $pending ?: null];
            }
            $xlsx->sheet('EC Information Board', $rows, $bold, [40, 10, 10, 10, 18]);
        }

        if ($document === 'family-list') {
            $rows = array_merge($head, [['Where', 'Family', 'Purok', 'Persons here', 'On the server']]);
            foreach ($data['centers'] as $row) {
                foreach ($row['families'] as $f) {
                    $rows[] = [$row['center']->name, $f['label'], $f['purok'], $f['here'], $f['remote_id'] ? 'Yes' : 'Not yet synced'];
                }
            }
            foreach ($data['privateHouses']['families'] as $f) {
                $rows[] = ['Private house (with '.$f['host'].')', $f['label'], null, $f['persons'], $f['waiting'] ? 'Not yet synced' : 'Yes'];
            }
            $xlsx->sheet('Family list', $rows, [0, 5], [34, 32, 16, 12, 16]);
        }

        if ($document === 'families-to-evacuate') {
            $rows = array_merge($head, [['Purok', 'High risk', 'Head of the family', 'Members', 'Contact', 'Status', 'Note']]);
            foreach ($data['list']['groups'] as $group) {
                foreach ($group['families'] as $f) {
                    $rows[] = [$group['name'], $group['high_risk'] ? ($group['risk'] ?? 'Yes') : null, $f['head'] ?? null, $f['members_count'],
                        $f['contact_number'] ?? null, $f['is_checked_in'] ? 'Checked in' : 'Expected', $f['note'] ?? null];
                }
            }
            $rows[] = [];
            $rows[] = ['Total', null, Plural::of($data['list']['summary']['families'], 'family', 'families'), $data['list']['summary']['persons'], null,
                number_format($data['list']['summary']['checked_in']).' checked in'];
            $xlsx->sheet('Families to evacuate', $rows, [0, 5, count($rows) - 1], [22, 18, 30, 10, 16, 14, 30]);
        }

        return $xlsx->toString();
    }

    /** Every age bracket label, for the print views. */
    public static function ageBrackets(): array
    {
        return EcBoardEntry::AGE_BRACKETS;
    }
}
