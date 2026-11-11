<?php
declare(strict_types=1);

namespace CollectorRemote\Providers;

use RuntimeException;

/** OpenStreetMap/Overpass: bounded query, no inferred ratings, addresses or fabricated fallback. */
final class OsmProvider implements ProviderInterface
{
    public function __construct(private array $config, private int $timeout = 25) {}
    private bool $possiblyTruncated = false;
    public function name(): string { return 'osm'; }
    public function possiblyTruncated(): bool { return $this->possiblyTruncated; }

    public function search(array $job): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('افزونه cURL در PHP فعال نیست.');
        $bbox = $job['bbox'] ?? null;
        if (!is_array($bbox) || count($bbox) !== 4 || count(array_filter($bbox, 'is_numeric')) !== 4) {
            throw new RuntimeException('محدوده جغرافیایی معتبر برای شهر تنظیم نشده است.');
        }
        [$south, $west, $north, $east] = array_map('floatval', array_values($bbox));
        if ($south < -90 || $north > 90 || $west < -180 || $east > 180 || $south >= $north || $west >= $east
            || ($north - $south) > 1 || ($east - $west) > 1) {
            throw new RuntimeException('محدوده شهر نامعتبر یا بیش از حد بزرگ است.');
        }
        $shorthandMap = [
            'کافه' => 'amenity=cafe',
            'رستوران' => 'amenity=restaurant',
            'کافه و رستوران' => 'amenity=cafe',
            'سالن زیبایی' => 'shop=beauty',
            'کلینیک و دندانپزشکی' => 'amenity=clinic',
            'دندانپزشکی' => 'amenity=dentist',
            'ویلا و اقامتگاه' => 'tourism=guest_house',
            'جاذبه گردشگری' => 'tourism=attraction',
        ];
        $filters = [];
        foreach ((array) ($job['queries'] ?? []) as $query) {
            $query = trim((string) $query);
            if (isset($shorthandMap[$query])) $query = $shorthandMap[$query];
            if (!preg_match('/^(amenity|shop|tourism|historic|healthcare|leisure|natural)=[a-z0-9_]+$/', $query)) {
                throw new RuntimeException('عبارت OSM باید یک تگ معتبر مانند amenity=cafe یا tourism=hotel باشد.');
            }
            [$key, $value] = explode('=', $query, 2);
            $filters[] = sprintf('nwr["%s"="%s"](%F,%F,%F,%F);', $key, $value, $south, $west, $north, $east);
        }
        if (!$filters || count($filters) > 10) throw new RuntimeException('بین یک تا ده عبارت OSM لازم است.');
        $timeout = max(5, min(25, $this->timeout));
        $ql = sprintf('[out:json][timeout:%d];(%s);out center 200;', $timeout, implode('', $filters));
        $endpoint = (string) ($this->config['overpass_endpoint'] ?? 'https://overpass-api.de/api/interpreter');
        if (!str_starts_with($endpoint, 'https://')) throw new RuntimeException('آدرس Overpass باید HTTPS باشد.');
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['data'=>$ql]),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => (string) ($this->config['user_agent'] ?? 'HamiHubX/1.0 (OpenStreetMap directory)'),
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) throw new RuntimeException("خطای Overpass (HTTP {$status}): {$error}", $status);
        $data = json_decode((string) $raw, true);
        if (!is_array($data) || !isset($data['elements']) || !is_array($data['elements']) || !empty($data['remark'])) {
            throw new RuntimeException('پاسخ Overpass نامعتبر یا ناقص است.');
        }
        $this->possiblyTruncated = count($data['elements']) >= 200;
        return self::mapElements($data['elements']);
    }

    /** Transform only source-supplied facts. Public for fixture-based tests, no network required. */
    public static function mapElements(array $elements): array
    {
        $results = [];
        foreach ($elements as $el) {
            if (!is_array($el)) continue;
            $tags = is_array($el['tags'] ?? null) ? $el['tags'] : [];
            $name = trim((string) ($tags['name:fa'] ?? $tags['name'] ?? ''));
            $type = (string) ($el['type'] ?? '');
            $id = (string) ($el['id'] ?? '');
            if ($name === '' || !in_array($type, ['node','way','relation'], true) || !ctype_digit($id)) continue;
            $address = trim((string) ($tags['addr:full'] ?? ''));
            if ($address === '') {
                $address = trim(implode(' ', array_filter([(string) ($tags['addr:street'] ?? ''), (string) ($tags['addr:housenumber'] ?? '')])));
            }
            $phone = trim((string) ($tags['phone'] ?? $tags['contact:phone'] ?? $tags['mobile'] ?? $tags['contact:mobile'] ?? ''));
            $osmCategory = (string) ($tags['amenity'] ?? $tags['shop'] ?? $tags['tourism'] ?? $tags['historic'] ?? $tags['healthcare'] ?? $tags['leisure'] ?? $tags['natural'] ?? '');
            $isClosed = isset($tags['disused:amenity']) || isset($tags['closed:amenity'])
                || isset($tags['disused:shop']) || isset($tags['closed:shop'])
                || isset($tags['disused:tourism']) || isset($tags['closed:tourism']);
            $results[] = ['name'=>$name, 'category'=>$osmCategory,
                'address'=>$address, 'lat'=>$el['lat'] ?? $el['center']['lat'] ?? null, 'lng'=>$el['lon'] ?? $el['center']['lon'] ?? null,
                'phones'=>$phone !== '' ? array_map('trim', preg_split('/[;,\/]+/', $phone) ?: []) : [],
                'hours'=>(string) ($tags['opening_hours'] ?? ''), 'rating'=>null, 'reviews_count'=>0,
                'permanently_closed'=>$isClosed,
                'source'=>'osm', 'source_id'=>$type . '/' . $id,
                'source_url'=>'https://www.openstreetmap.org/' . $type . '/' . $id];
        }
        return $results;
    }
    public function details(string $sourceId): ?array { return null; }
}
