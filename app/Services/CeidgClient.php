<?php
namespace Duir\Services;

use Duir\Config;
use Duir\Support\Client;
use Duir\Support\Normalize;

/**
 * Potwierdzenie wpisu osoby fizycznej prowadzącej działalność w CEIDG
 * (api dane.biznes.gov.pl, v3). Osoby fizyczne NIE podlegają KRS — dla nich
 * to CEIDG odpowiada na pytanie "czy działalność jest aktywna, zawieszona,
 * czy wykreślona". Wymaga klucza API (JWT) z pola CEIDG_API_KEY w Ustawieniach.
 */
final class CeidgClient
{
    private const STATUS_LABELS = [
        'AKTYWNY' => 'działalność aktywna',
        'ZAWIESZONY' => 'działalność zawieszona',
        'WYKRESLONY' => 'działalność wykreślona',
        'OCZEKUJE_NA_ROZPOCZECIE_DZIALALNOSCI' => 'oczekuje na rozpoczęcie działalności',
        'WYLACZNIE_W_FORMIE_SPOLKI' => 'działalność wyłącznie w formie spółki',
    ];

    public function __construct(private ?Client $http = null) { $this->http ??= new Client(); }

    public function confirm(array $subject): array
    {
        $key = trim((string)Config::get('CEIDG_API_KEY', ''));
        if ($key === '') {
            return ['source'=>'CEIDG','status'=>'skipped','label'=>'Pominięto CEIDG: brak klucza API — uzupełnij pole „CEIDG — klucz API" w Ustawieniach.'];
        }
        $nip = Normalize::digits($subject['nip'] ?? '');
        $regon = Normalize::digits($subject['regon'] ?? '');
        if ($nip === '' && $regon === '') {
            // Sam PESEL nie wystarcza — publiczne API CEIDG wyszukuje po NIP/REGON.
            return ['source'=>'CEIDG','status'=>'skipped','label'=>'Pominięto CEIDG: brak NIP/REGON (samym PESEL nie da się wyszukać wpisu).'];
        }
        $query = $nip !== '' ? 'nip='.$nip : 'regon='.$regon;
        // API v2 zostało wycofane i zwraca HTTP 404. Oficjalnym endpointem
        // produkcyjnym Hurtowni Danych CEIDG jest obecnie API v3.
        $data = $this->http->getJson('https://dane.biznes.gov.pl/api/ceidg/v3/firmy?'.$query, ['Authorization' => 'Bearer '.$key]);
        $firma = $data['firmy'][0] ?? ($data['firma'][0] ?? null);
        if (!$firma) {
            return ['source'=>'CEIDG','status'=>'no_results','label'=>'CEIDG: nie znaleziono wpisu dla podanego identyfikatora.'];
        }
        $status = strtoupper(Normalize::fold((string)($firma['status'] ?? '')));
        $status = str_replace(' ', '_', $status);
        $name = Normalize::text((string)($firma['nazwa'] ?? ''));
        $label = 'CEIDG: '.(self::STATUS_LABELS[$status] ?? ('status: '.strtolower($status ?: 'nieznany')))
            .($name !== '' ? ' — '.$name : '');
        return [
            'source'=>'CEIDG','status'=>'success','ceidg_status'=>$status,'label'=>$label,
            'legal_name'=>$name,
            'raw_json'=>['status'=>$firma['status'] ?? null,'nazwa'=>$name,'nip'=>$firma['wlasciciel']['nip'] ?? $nip,'data_rozpoczecia'=>$firma['dataRozpoczecia'] ?? null],
        ];
    }
}
