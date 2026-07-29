<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\HttpException;

final class WeatherService
{
    public static function current(
        float $latitude,
        float $longitude,
        string $locationName = '',
        string $question = ''
    ): array
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new HttpException('定位坐标无效，请重新获取当前位置', 0, 422);
        }

        $cachePath = self::cachePath($latitude, $longitude);
        $cacheSeconds = max(60, (int) config('weather.cache_seconds', 600));
        $cached = self::readCache($cachePath);
        if ($cached !== null && (time() - (int) ($cached['cached_at'] ?? 0)) <= $cacheSeconds) {
            return self::normalize($cached['payload'] ?? [], $latitude, $longitude, $locationName, $question, false);
        }

        try {
            $payload = self::requestProvider($latitude, $longitude);
            self::writeCache($cachePath, $payload);
            return self::normalize($payload, $latitude, $longitude, $locationName, $question, false);
        } catch (\Throwable $exception) {
            $staleSeconds = max($cacheSeconds, (int) config('weather.stale_cache_seconds', 21600));
            if ($cached !== null && (time() - (int) ($cached['cached_at'] ?? 0)) <= $staleSeconds) {
                return self::normalize($cached['payload'] ?? [], $latitude, $longitude, $locationName, $question, true);
            }
            if ($exception instanceof HttpException) throw $exception;
            throw new HttpException('天气服务暂时不可用，请稍后再试', 0, 502);
        }
    }

    public static function isWeatherQuestion(string $question): bool
    {
        $value = mb_strtolower(trim($question));
        if ($value === '') return false;
        foreach ([
            '天气', '气温', '温度', '体感', '预报', '下雨', '降雨', '带伞', '雨伞', '下雪',
            '风力', '风速', '湿度', '气压', '紫外线', '日出', '日落', '穿什么', '防晒',
            '能洗车', '适合运动', 'weather', 'temperature', 'forecast', 'rain', 'snow',
            'humidity', 'pressure', 'wind', 'uv index',
        ] as $keyword) {
            if (str_contains($value, $keyword)) return true;
        }
        return false;
    }

    public static function extractLocationQuery(string $question): string
    {
        if (!self::isWeatherQuestion($question)) return '';
        $value = trim($question);
        if ($value === '') return '';

        if (preg_match('/(?:weather|forecast|temperature)\s+(?:in|for)\s+([a-z][a-z .\'-]{1,60})/iu', $value, $match)) {
            return self::cleanLocationCandidate((string) ($match[1] ?? ''));
        }
        if (preg_match('/([a-z][a-z .\'-]{1,60}?)\s+(?:weather|forecast|temperature)(?:\s|$)/iu', $value, $match)) {
            return self::cleanLocationCandidate((string) ($match[1] ?? ''));
        }

        $keywordIndex = null;
        $matchedKeyword = '';
        foreach ([
            '天气', '气温', '温度', '体感', '预报', '下雨', '降雨', '带伞', '雨伞', '下雪',
            '风力', '风速', '湿度', '气压', '紫外线', '日出', '日落', '穿什么', '防晒',
            '能洗车', '适合运动',
        ] as $keyword) {
            $index = mb_strpos($value, $keyword);
            if ($index !== false && ($keywordIndex === null || $index < $keywordIndex)) {
                $keywordIndex = $index;
                $matchedKeyword = $keyword;
            }
        }
        if ($keywordIndex === null) return '';
        $before = self::cleanLocationCandidate(mb_substr($value, 0, $keywordIndex));
        if ($before !== '') return $before;
        if ($matchedKeyword === '') return '';
        return self::cleanLocationCandidate(
            mb_substr($value, $keywordIndex + mb_strlen($matchedKeyword))
        );
    }

    public static function resolveLocation(string $query): array
    {
        $query = self::cleanLocationCandidate($query);
        if ($query === '') throw new HttpException('请提供需要查询天气的城市或地区', 0, 422);

        $cachePath = self::geocodingCachePath($query);
        $cacheSeconds = max(3600, (int) config('weather.geocoding_cache_seconds', 2592000));
        $cached = self::readCache($cachePath);
        if ($cached !== null && (time() - (int) ($cached['cached_at'] ?? 0)) <= $cacheSeconds) {
            $payload = $cached['payload'] ?? null;
            if (is_array($payload) && isset($payload['latitude'], $payload['longitude'])) {
                return self::publicLocationPayload($payload, $query);
            }
        }

        $resolved = null;
        $providerFailures = [];
        if (function_exists('curl_init')) {
            // Nominatim ranks globally significant places correctly for localized
            // names such as "东京". Open-Meteo's name-only search can otherwise
            // prefer a small same-name settlement in another country.
            $secondaryEndpoint = rtrim((string) config(
                'weather.secondary_geocoding_endpoint',
                'https://nominatim.openstreetmap.org/search'
            ), '?');
            foreach (self::geocodingCandidates($query) as $candidate) {
                try {
                    $result = self::requestSecondaryGeocoding($secondaryEndpoint, $candidate);
                    if ($result === null) continue;
                    $resolved = self::normalizeSecondaryGeocodingResult($result, $query);
                    break;
                } catch (\Throwable $exception) {
                    $providerFailures[] = $exception;
                }
            }

            if ($resolved === null) {
                $endpoint = rtrim((string) config(
                    'weather.geocoding_endpoint',
                    'https://geocoding-api.open-meteo.com/v1/search'
                ), '?');
                foreach (self::geocodingCandidates($query) as $candidate) {
                    try {
                        $result = self::requestGeocoding($endpoint, $candidate);
                        if ($result === null) continue;
                        $resolved = self::normalizeGeocodingResult($result, $query);
                        break;
                    } catch (\Throwable $exception) {
                        $providerFailures[] = $exception;
                    }
                }
            }
        }

        if ($resolved === null && !function_exists('curl_init')) {
            throw new HttpException('服务器未启用 cURL，暂时无法解析该地点', 0, 503);
        }
        if ($resolved === null && count($providerFailures) >= 2) {
            throw new HttpException('地点解析服务暂时不可用，请稍后再试', 0, 502);
        }
        if ($resolved === null) {
            throw new HttpException('没有找到“' . mb_substr($query, 0, 40) . '”，请换用更完整的城市或地区名称', 0, 404);
        }
        $resolved = self::publicLocationPayload($resolved, $query);
        self::writeCache($cachePath, $resolved);
        return $resolved;
    }

    private static function publicLocationPayload(array $location, string $fallbackName): array
    {
        return [
            'latitude' => round((float) ($location['latitude'] ?? 0), 6),
            'longitude' => round((float) ($location['longitude'] ?? 0), 6),
            'location_name' => trim((string) ($location['location_name'] ?? '')) ?: $fallbackName,
            'timezone' => (string) ($location['timezone'] ?? ''),
        ];
    }

    private static function requestGeocoding(string $endpoint, string $query): ?array
    {
        $url = $endpoint . '?' . http_build_query([
            'name' => $query,
            'count' => 5,
            'language' => 'zh',
            'format' => 'json',
        ], '', '&', PHP_QUERY_RFC3986);
        $curl = curl_init($url);
        if ($curl === false) throw new HttpException('地点解析服务初始化失败', 0, 502);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(2, (int) config('weather.connect_timeout', 4)),
            CURLOPT_TIMEOUT => max(4, (int) config('weather.timeout', 8)),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: Yiyunying-Backend/2.6',
            ],
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($raw) || $status < 200 || $status >= 300) {
            $detail = $error !== '' ? $error : ('HTTP ' . $status);
            throw new HttpException('地点解析失败：' . mb_substr($detail, 0, 80), 0, 502);
        }
        $decoded = json_decode($raw, true);
        $results = is_array($decoded['results'] ?? null) ? $decoded['results'] : [];
        $result = self::bestOpenMeteoResult($results, $query);
        if ($result === null || !is_numeric($result['latitude'] ?? null) || !is_numeric($result['longitude'] ?? null)) {
            return null;
        }
        return $result;
    }

    private static function requestSecondaryGeocoding(string $endpoint, string $query): ?array
    {
        $url = $endpoint . '?' . http_build_query([
            'q' => $query,
            'format' => 'jsonv2',
            'limit' => 5,
            'addressdetails' => 1,
            'accept-language' => 'zh-CN,zh',
        ], '', '&', PHP_QUERY_RFC3986);
        $curl = curl_init($url);
        if ($curl === false) throw new HttpException('备用地点解析服务初始化失败', 0, 502);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(2, (int) config('weather.connect_timeout', 4)),
            CURLOPT_TIMEOUT => max(4, (int) config('weather.timeout', 8)),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Accept-Language: zh-CN,zh;q=0.9',
                'User-Agent: Yiyunying-Backend/2.7 (geocoding; contact=admin@appht.jjmxg.xyz)',
            ],
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($raw) || $status < 200 || $status >= 300) {
            $detail = $error !== '' ? $error : ('HTTP ' . $status);
            throw new HttpException('备用地点解析失败：' . mb_substr($detail, 0, 80), 0, 502);
        }
        $decoded = json_decode($raw, true);
        $result = self::bestNominatimResult(is_array($decoded) ? $decoded : [], $query);
        if ($result === null || !is_numeric($result['lat'] ?? null) || !is_numeric($result['lon'] ?? null)) {
            return null;
        }
        return $result;
    }

    private static function normalizeGeocodingResult(array $result, string $query): array
    {
        $parts = [];
        foreach (['admin1', 'admin2', 'admin3', 'name'] as $key) {
            $part = trim((string) ($result[$key] ?? ''));
            if ($part !== '' && !in_array($part, $parts, true)) $parts[] = $part;
        }
        return [
            'latitude' => round((float) $result['latitude'], 6),
            'longitude' => round((float) $result['longitude'], 6),
            'location_name' => $parts === [] ? $query : implode(' ', $parts),
            'timezone' => (string) ($result['timezone'] ?? ''),
        ];
    }

    private static function normalizeSecondaryGeocodingResult(array $result, string $query): array
    {
        $address = is_array($result['address'] ?? null) ? $result['address'] : [];
        $parts = [];
        foreach (['country', 'province', 'state', 'city', 'municipality', 'county', 'district', 'town', 'village', 'suburb'] as $key) {
            $part = trim((string) ($address[$key] ?? ''));
            if ($part !== '' && !in_array($part, $parts, true)) $parts[] = $part;
        }
        $displayName = trim((string) ($result['display_name'] ?? ''));
        return [
            'latitude' => round((float) $result['lat'], 6),
            'longitude' => round((float) $result['lon'], 6),
            'location_name' => $parts !== []
                ? implode(' ', $parts)
                : ($displayName !== '' ? mb_substr($displayName, 0, 80) : $query),
            'timezone' => '',
        ];
    }

    private static function geocodingCandidates(string $query): array
    {
        $items = [$query];
        if (preg_match('/[\x{3400}-\x{9fff}]/u', $query) && !str_contains($query, '中国')) {
            $items[] = $query . ' 中国';
        }
        $items = array_values(array_unique(array_filter(array_map('trim', $items))));
        return $items;
    }

    private static function bestNominatimResult(array $results, string $query): ?array
    {
        $best = null;
        $bestScore = -INF;
        foreach ($results as $index => $result) {
            if (!is_array($result) || !is_numeric($result['lat'] ?? null) || !is_numeric($result['lon'] ?? null)) continue;
            $name = trim((string) ($result['name'] ?? ''));
            $displayName = trim((string) ($result['display_name'] ?? ''));
            $score = (float) ($result['importance'] ?? 0) * 100;
            if (self::placeNameMatches($name, $query)) $score += 180;
            elseif (self::placeNameMatches($displayName, $query)) $score += 80;
            $rank = (int) ($result['place_rank'] ?? 30);
            if ($rank <= 16) $score += 30;
            $score -= $index;
            if ($score > $bestScore) {
                $best = $result;
                $bestScore = $score;
            }
        }
        return $best;
    }

    private static function bestOpenMeteoResult(array $results, string $query): ?array
    {
        $best = null;
        $bestScore = -INF;
        foreach ($results as $index => $result) {
            if (!is_array($result) || !is_numeric($result['latitude'] ?? null) || !is_numeric($result['longitude'] ?? null)) continue;
            $score = self::placeNameMatches((string) ($result['name'] ?? ''), $query) ? 100.0 : 0.0;
            $population = max(0, (int) ($result['population'] ?? 0));
            if ($population > 0) $score += min(80, log10($population + 1) * 12);
            $feature = strtoupper((string) ($result['feature_code'] ?? ''));
            if (in_array($feature, ['PPLC', 'PPLA', 'PPLA2', 'ADM1', 'ADM2'], true)) $score += 35;
            $score -= $index;
            if ($score > $bestScore) {
                $best = $result;
                $bestScore = $score;
            }
        }
        return $best;
    }

    private static function placeNameMatches(string $candidate, string $query): bool
    {
        $normalize = static function (string $value): string {
            $value = mb_strtolower(trim($value));
            return (string) preg_replace('/(?:市|区|县|州|省|自治区|特别行政区|都|府|東京都|[\s\/\\,，.。·\-])+$/u', '', $value);
        };
        $left = $normalize($candidate);
        $right = $normalize($query);
        return $left !== '' && $right !== '' && ($left === $right || str_contains($left, $right));
    }

    private static function requestProvider(float $latitude, float $longitude): array
    {
        if (!function_exists('curl_init')) {
            throw new HttpException('服务器未启用 cURL，暂时无法查询天气', 0, 503);
        }
        $endpoint = rtrim((string) config('weather.endpoint', 'https://api.open-meteo.com/v1/forecast'), '?');
        $query = http_build_query([
            'latitude' => round($latitude, 6),
            'longitude' => round($longitude, 6),
            'current' => implode(',', [
                'temperature_2m', 'apparent_temperature', 'relative_humidity_2m',
                'precipitation', 'rain', 'weather_code', 'cloud_cover',
                'wind_speed_10m', 'wind_direction_10m', 'surface_pressure', 'is_day',
            ]),
            'hourly' => implode(',', [
                'temperature_2m', 'weather_code', 'precipitation_probability',
                'precipitation', 'rain',
            ]),
            'daily' => implode(',', [
                'weather_code', 'temperature_2m_max', 'temperature_2m_min',
                'precipitation_probability_max', 'precipitation_sum', 'uv_index_max',
                'sunrise', 'sunset', 'wind_speed_10m_max',
            ]),
            'timezone' => 'auto',
            'forecast_days' => 7,
        ], '', '&', PHP_QUERY_RFC3986);

        $curl = curl_init($endpoint . '?' . $query);
        if ($curl === false) throw new HttpException('天气服务初始化失败', 0, 502);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(2, (int) config('weather.connect_timeout', 4)),
            CURLOPT_TIMEOUT => max(4, (int) config('weather.timeout', 8)),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: Yiyunying-Backend/2.6',
            ],
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($raw) || $status < 200 || $status >= 300) {
            $detail = $error !== '' ? $error : ('HTTP ' . $status);
            throw new HttpException('天气服务请求失败：' . mb_substr($detail, 0, 80), 0, 502);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['current'] ?? null)) {
            throw new HttpException('天气服务返回内容不完整', 0, 502);
        }
        return $decoded;
    }

    private static function normalize(
        array $payload,
        float $latitude,
        float $longitude,
        string $locationName,
        string $question,
        bool $stale
    ): array {
        $current = is_array($payload['current'] ?? null) ? $payload['current'] : [];
        $daily = is_array($payload['daily'] ?? null) ? $payload['daily'] : [];
        $hourly = is_array($payload['hourly'] ?? null) ? $payload['hourly'] : [];
        $code = (int) ($current['weather_code'] ?? 0);
        $condition = self::condition($code, (int) ($current['cloud_cover'] ?? 0));
        $forecast = [];
        $dates = is_array($daily['time'] ?? null) ? $daily['time'] : [];
        foreach ($dates as $index => $date) {
            $dailyCode = (int) (($daily['weather_code'][$index] ?? 0));
            $dailyCondition = self::condition($dailyCode, 0);
            $forecast[] = [
                'date' => (string) $date,
                'condition_key' => $dailyCondition['key'],
                'condition_name' => $dailyCondition['name'],
                'weather_code' => $dailyCode,
                'temperature_max' => self::number($daily['temperature_2m_max'][$index] ?? null),
                'temperature_min' => self::number($daily['temperature_2m_min'][$index] ?? null),
                'precipitation_probability' => (int) ($daily['precipitation_probability_max'][$index] ?? 0),
                'precipitation_sum' => self::number($daily['precipitation_sum'][$index] ?? null),
                'uv_index' => self::number($daily['uv_index_max'][$index] ?? null),
                'sunrise' => (string) ($daily['sunrise'][$index] ?? ''),
                'sunset' => (string) ($daily['sunset'][$index] ?? ''),
                'wind_speed_max' => self::number($daily['wind_speed_10m_max'][$index] ?? null),
            ];
        }

        $resolvedName = trim($locationName);
        if ($resolvedName === '') $resolvedName = '当前位置';
        $temperature = self::number($current['temperature_2m'] ?? null);
        $high = self::number($daily['temperature_2m_max'][0] ?? null);
        $low = self::number($daily['temperature_2m_min'][0] ?? null);
        $requestedDay = min(self::requestedDayIndex($question), max(0, count($forecast) - 1));
        $requested = $forecast[$requestedDay] ?? [
            'condition_name' => $condition['name'],
            'temperature_max' => $high,
            'temperature_min' => $low,
            'precipitation_probability' => 0,
        ];
        $requestedLabel = $requestedDay === 1 ? '明天' : ($requestedDay === 2 ? '后天' : '今天');
        $requestedDate = (string) ($requested['date'] ?? ($dates[$requestedDay] ?? ''));
        $hourlyForecast = [];
        $hourlyTimes = is_array($hourly['time'] ?? null) ? $hourly['time'] : [];
        foreach ($hourlyTimes as $index => $time) {
            $timeValue = (string) $time;
            if ($requestedDate === '' || !str_starts_with($timeValue, $requestedDate)) continue;
            $hourlyCode = (int) ($hourly['weather_code'][$index] ?? 0);
            $hourlyCondition = self::condition($hourlyCode, 0);
            $hourlyForecast[] = [
                'time' => $timeValue,
                'time_label' => substr($timeValue, 11, 5),
                'temperature' => self::number($hourly['temperature_2m'][$index] ?? null),
                'weather_code' => $hourlyCode,
                'condition_key' => $hourlyCondition['key'],
                'condition_name' => $hourlyCondition['name'],
                'precipitation_probability' => (int) ($hourly['precipitation_probability'][$index] ?? 0),
                'precipitation' => self::number($hourly['precipitation'][$index] ?? null),
                'rain' => self::number($hourly['rain'][$index] ?? null),
            ];
        }
        $rainPeriods = self::rainPeriods($hourlyForecast);
        $precipitationProbability = (int) ($daily['precipitation_probability_max'][0] ?? 0);
        $uvIndex = self::number($daily['uv_index_max'][0] ?? null);
        $advice = self::advice(
            $temperature,
            self::number($current['apparent_temperature'] ?? $temperature),
            $precipitationProbability,
            $uvIndex,
            $condition['key']
        );
        $summary = $requestedDay === 0
            ? sprintf(
                '%s现在%s，%.1f℃，体感%.1f℃；今天最高%.1f℃，最低%.1f℃，降雨概率%d%%。%s',
                $resolvedName,
                $condition['name'],
                $temperature,
                self::number($current['apparent_temperature'] ?? $temperature),
                $high,
                $low,
                $precipitationProbability,
                $advice[0] ?? ''
            )
            : sprintf(
                '%s%s%s，预计最高%.1f℃，最低%.1f℃，降雨概率%d%%。%s',
                $resolvedName,
                $requestedLabel,
                (string) ($requested['condition_name'] ?? ''),
                self::number($requested['temperature_max'] ?? null),
                self::number($requested['temperature_min'] ?? null),
                (int) ($requested['precipitation_probability'] ?? 0),
                ((int) ($requested['precipitation_probability'] ?? 0)) >= 40 ? '出门建议带伞。' : '降雨可能性不高。'
            );
        if (self::asksRainTiming($question)) {
            if ($rainPeriods !== []) {
                $firstPeriod = $rainPeriods[0];
                $periodLabels = array_map(
                    static fn(array $period): string => (string) $period['start_time'] . '-' . (string) $period['end_time'],
                    array_slice($rainPeriods, 0, 3)
                );
                $summary = sprintf(
                    '%s%s预计%s左右开始出现降雨，主要降雨时段为%s，最高降雨概率%d%%，预计累计降水%.1f毫米。建议在首个时段前安排出行并随身带伞。',
                    $resolvedName,
                    $requestedLabel,
                    (string) $firstPeriod['start_time'],
                    implode('、', $periodLabels),
                    max(array_column($rainPeriods, 'peak_probability')),
                    array_sum(array_column($rainPeriods, 'precipitation_sum'))
                );
            } elseif ($hourlyForecast !== []) {
                $peak = $hourlyForecast[0];
                foreach ($hourlyForecast as $hour) {
                    if ((int) $hour['precipitation_probability'] > (int) $peak['precipitation_probability']) $peak = $hour;
                }
                $summary = sprintf(
                    '%s%s逐小时预报暂未出现明确降雨时段；最高降雨概率为%d%%，出现在%s左右。当前可以正常安排出行，临近时段仍建议留意预报更新。',
                    $resolvedName,
                    $requestedLabel,
                    (int) $peak['precipitation_probability'],
                    (string) $peak['time_label']
                );
            }
        }
        return [
            'location_name' => mb_substr($resolvedName, 0, 80),
            'latitude' => round($latitude, 6),
            'longitude' => round($longitude, 6),
            'timezone' => (string) ($payload['timezone'] ?? ''),
            'condition_key' => $condition['key'],
            'condition_name' => $condition['name'],
            'weather_code' => $code,
            'temperature' => $temperature,
            'apparent_temperature' => self::number($current['apparent_temperature'] ?? null),
            'humidity' => (int) ($current['relative_humidity_2m'] ?? 0),
            'cloud_cover' => (int) ($current['cloud_cover'] ?? 0),
            'precipitation' => self::number($current['precipitation'] ?? null),
            'rain' => self::number($current['rain'] ?? null),
            'wind_speed' => self::number($current['wind_speed_10m'] ?? null),
            'wind_direction' => (int) ($current['wind_direction_10m'] ?? 0),
            'surface_pressure' => self::number($current['surface_pressure'] ?? null),
            'is_day' => (int) ($current['is_day'] ?? 1),
            'precipitation_probability' => $precipitationProbability,
            'uv_index' => $uvIndex,
            'sunrise' => (string) ($daily['sunrise'][0] ?? ''),
            'sunset' => (string) ($daily['sunset'][0] ?? ''),
            'temperature_max' => $high,
            'temperature_min' => $low,
            'observed_at' => (string) ($current['time'] ?? ''),
            'source' => 'Open-Meteo',
            'stale' => $stale,
            'requested_day_index' => $requestedDay,
            'requested_day_label' => $requestedLabel,
            'advice' => $advice,
            'forecast' => $forecast,
            'hourly_forecast' => $hourlyForecast,
            'rain_periods' => $rainPeriods,
            'next_rain_time' => $rainPeriods === [] ? '' : (string) $rainPeriods[0]['start_time'],
            'summary' => $summary,
        ];
    }

    private static function asksRainTiming(string $question): bool
    {
        $value = mb_strtolower($question);
        foreach (['几点', '什么时候', '何时', '多久', '下雨', '降雨', '雨几点', 'rain'] as $keyword) {
            if (str_contains($value, $keyword)) return true;
        }
        return false;
    }

    private static function rainPeriods(array $hours): array
    {
        $periods = [];
        $active = null;
        foreach ($hours as $hour) {
            $probability = (int) ($hour['precipitation_probability'] ?? 0);
            $precipitation = (float) ($hour['precipitation'] ?? 0.0);
            $rain = (float) ($hour['rain'] ?? 0.0);
            $rainy = $probability >= 35 || $precipitation >= 0.1 || $rain >= 0.1;
            if (!$rainy) {
                if ($active !== null) {
                    $periods[] = self::finishRainPeriod($active);
                    $active = null;
                }
                continue;
            }
            if ($active === null) {
                $active = [
                    'start_time' => (string) ($hour['time_label'] ?? ''),
                    'last_time' => (string) ($hour['time'] ?? ''),
                    'peak_probability' => $probability,
                    'precipitation_sum' => $precipitation,
                ];
            } else {
                $active['last_time'] = (string) ($hour['time'] ?? '');
                $active['peak_probability'] = max((int) $active['peak_probability'], $probability);
                $active['precipitation_sum'] = (float) $active['precipitation_sum'] + $precipitation;
            }
        }
        if ($active !== null) $periods[] = self::finishRainPeriod($active);
        return $periods;
    }

    private static function finishRainPeriod(array $period): array
    {
        $timestamp = strtotime((string) ($period['last_time'] ?? ''));
        $period['end_time'] = $timestamp === false ? (string) $period['start_time'] : date('H:i', $timestamp + 3600);
        unset($period['last_time']);
        $period['precipitation_sum'] = round((float) $period['precipitation_sum'], 1);
        return $period;
    }

    private static function requestedDayIndex(string $question): int
    {
        $value = mb_strtolower($question);
        if (str_contains($value, '后天') || str_contains($value, 'day after tomorrow')) return 2;
        if (str_contains($value, '明天') || str_contains($value, '明日') || str_contains($value, 'tomorrow')) return 1;
        return 0;
    }

    private static function advice(
        float $temperature,
        float $apparent,
        int $precipitationProbability,
        float $uvIndex,
        string $conditionKey
    ): array {
        $items = [];
        if ($precipitationProbability >= 40 || in_array($conditionKey, ['rain', 'storm', 'snow'], true)) {
            $items[] = '建议随身带伞，注意路面湿滑。';
        } else {
            $items[] = '短时降水可能性较低，可正常安排出行。';
        }
        $felt = $apparent !== 0.0 ? $apparent : $temperature;
        if ($felt >= 30) $items[] = '体感偏热，适合轻薄透气衣物并及时补水。';
        elseif ($felt <= 10) $items[] = '体感偏凉，建议增加外套并注意保暖。';
        else $items[] = '体感较舒适，可按日常穿着出行。';
        if ($uvIndex >= 6) $items[] = '紫外线较强，外出建议做好防晒。';
        elseif ($uvIndex >= 3) $items[] = '紫外线中等，长时间户外可适当防晒。';
        else $items[] = '紫外线较弱，常规防护即可。';
        return $items;
    }

    private static function condition(int $code, int $cloudCover): array
    {
        if ($code === 0 && $cloudCover < 35) return ['key' => 'sunny', 'name' => '晴'];
        if (in_array($code, [1, 2, 3], true)) {
            return $code === 1 ? ['key' => 'partly_cloudy', 'name' => '多云间晴'] : ['key' => 'cloudy', 'name' => '多云'];
        }
        if (in_array($code, [45, 48], true)) return ['key' => 'fog', 'name' => '雾'];
        if (in_array($code, [51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82], true)) {
            return ['key' => 'rain', 'name' => '有雨'];
        }
        if (in_array($code, [71, 73, 75, 77, 85, 86], true)) return ['key' => 'snow', 'name' => '有雪'];
        if (in_array($code, [95, 96, 99], true)) return ['key' => 'storm', 'name' => '雷雨'];
        if ($cloudCover >= 35) return ['key' => 'cloudy', 'name' => '多云'];
        return ['key' => 'sunny', 'name' => '晴'];
    }

    private static function number($value): float
    {
        return is_numeric($value) ? round((float) $value, 1) : 0.0;
    }

    private static function cachePath(float $latitude, float $longitude): string
    {
        $root = dirname(__DIR__, 2) . '/storage/cache/weather';
        if (!is_dir($root)) @mkdir($root, 0775, true);
        return $root . '/' . hash('sha256', 'hourly-v2:' . round($latitude, 2) . ':' . round($longitude, 2)) . '.json';
    }

    private static function geocodingCachePath(string $query): string
    {
        $root = dirname(__DIR__, 2) . '/storage/cache/weather-geocoding';
        if (!is_dir($root)) @mkdir($root, 0775, true);
        return $root . '/' . hash('sha256', 'global-v2:' . mb_strtolower(trim($query))) . '.json';
    }

    private static function cleanLocationCandidate(string $value): string
    {
        $result = trim((string) preg_replace('/[，,。.!！?？:：;；]+$/u', '', $value));
        $result = trim((string) preg_replace(
            '/^(?:请问|麻烦|帮我查一下|帮我查查|帮我看看|帮我查|查一下|查询|看一下|看看|我想知道|想知道|告诉我|请告诉我)+/u',
            '',
            $result
        ));
        $result = trim((string) preg_replace(
            '/(?:今天|今日|明天|明日|次日|后天|今晚|今夜|明晚|早上|上午|中午|下午|晚上|夜间|现在|目前|最近|近期|几点|什么时候|何时|大概|预计|开始|持续|多久|最高|最低|平均|实时)/u',
            '',
            $result
        ));
        // 时间范围词被移除后可能留下“到/至”，不能让它们混入地名查询。
        $result = trim((string) preg_replace('/^(?:从|到|至)+|(?:到|至)+$/u', '', $result));
        $result = trim((string) preg_replace('/^(?:在|查|看)/u', '', $result));
        $previous = null;
        while ($result !== '' && $previous !== $result) {
            $previous = $result;
            $result = trim((string) preg_replace(
                '/(?:今天|今日|明天|明日|次日|后天|今晚|今夜|明晚|早上|上午|中午|下午|晚上|夜间|现在|目前|最近|近期|这几天|这两天|这周|本周|未来|未来几天|未来一周|会不会|有没有|是否|可能|需要|会|要|的)$/u',
                '',
                $result
            ));
            $result = trim((string) preg_replace('/(?:未来)?[一二两三四五六七八九十\d]+天$/u', '', $result));
        }
        $normalized = mb_strtolower($result);
        foreach ([
            '今天', '今日', '明天', '明日', '次日', '后天', '今晚', '今夜', '明晚',
            '早上', '上午', '中午', '下午', '晚上', '夜间', '现在', '目前', '最近', '近期', '未来', '这周', '本周',
            '当地', '本地', '这里', '这儿', '附近', '当前位置', '我这里', '我这边', '所在位置',
            '哪里', '哪儿', '哪个地方', '什么地方', 'today', 'tomorrow', 'here', 'nearby',
            'current location', 'my location',
        ] as $blocked) {
            if ($normalized === $blocked || str_contains($normalized, $blocked)) return '';
        }
        if (mb_strlen($result) < 2 || mb_strlen($result) > 60) return '';
        if (preg_match('/(?:怎么|怎样|多少|强不强|穿什么|适合什么|能不能)/u', $result)) return '';
        return $result;
    }

    private static function readCache(string $path): ?array
    {
        if (!is_readable($path)) return null;
        $decoded = json_decode((string) @file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function writeCache(string $path, array $payload): void
    {
        $encoded = json_encode(['cached_at' => time(), 'payload' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded)) @file_put_contents($path, $encoded, LOCK_EX);
    }
}
