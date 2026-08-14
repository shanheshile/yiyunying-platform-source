<?php
declare(strict_types=1);

/**
 * Fixed, redacted contract for preparing the two reviewed catalog conflicts
 * entirely on the production host.  This file contains no production path,
 * media bytes, credential or caller-controlled executable.
 */

const CATALOG_CONFLICT_SERVER_LOCAL_STAGE_PATTERN =
    '#^/tmp/yiyunying-catalog-conflict-[0-9]{8}-[0-9]{6}-[0-9a-f]{16}$#D';
const CATALOG_CONFLICT_SERVER_LOCAL_RUNTIME_ROOT = '/opt/yiyunying/media-runtime';
const CATALOG_CONFLICT_SERVER_LOCAL_RUNTIME_VERSION = '8.1.2-3bfa407c614a';
const CATALOG_CONFLICT_SERVER_LOCAL_FFMPEG =
    '/opt/yiyunying/media-runtime/current/ffmpeg';
const CATALOG_CONFLICT_SERVER_LOCAL_FFPROBE =
    '/opt/yiyunying/media-runtime/current/ffprobe';
const CATALOG_CONFLICT_SERVER_LOCAL_FFMPEG_SHA256 =
    '7b3fb9508c20166ab3ba236a9585c3e22e903880723c1a6448e69ae6e4cd88d2';
const CATALOG_CONFLICT_SERVER_LOCAL_FFPROBE_SHA256 =
    'fe39eb91eb04dd18dff3870a87b59e41be997476c2d373c46ff7e12bb284743c';
const CATALOG_CONFLICT_SERVER_LOCAL_FFMPEG_SIZE = 140059552;
const CATALOG_CONFLICT_SERVER_LOCAL_FFPROBE_SIZE = 139834144;

/** @return array<string,array<string,mixed>> */
function catalogConflictServerLocalBindings(): array
{
    return [
        CATALOG_CONFLICT_REPAIR_ACTION_JPEG => [
            'path_sha256' => '6dba5a3f5092e15bad671d0d59c117f101e52ea58cd284079709568af52e3d29',
            'preimage' => [
                'sha256' => '089f4bf6be8a286314ccca1a046623109d1cbff905d4dd08e0a6506ad436c0fa',
                'size_bytes' => 30166,
            ],
            'content_kind' => 'jpeg',
            'codec_name' => 'mjpeg',
            'width' => 749,
            'height' => 421,
            'path_references' => 8,
            'upload_id_references' => 0,
            'upload_rows' => 0,
            'media_attachment_rows' => 0,
            'registration' => [
                'user_id' => null,
                'scene' => 'catalog_repair',
                'original_name' => 'catalog-repaired-image.png',
            ],
        ],
        CATALOG_CONFLICT_REPAIR_ACTION_HEIC => [
            'path_sha256' => '6e2f1db260b172345f8890c5360f187d1c0a8e331c1e108167134d9fa1fbf83f',
            'preimage' => [
                'sha256' => '6ff415d4bbad54d44b316075f7aef9d96a8210540902de36daa15db58e5d8e7c',
                'size_bytes' => 167590,
            ],
            'content_kind' => 'heic',
            'codec_name' => 'hevc',
            'width' => null,
            'height' => null,
            'path_references' => 3,
            'upload_id_references' => 1,
            'upload_rows' => 1,
            'media_attachment_rows' => 1,
            'registration' => null,
        ],
    ];
}

function catalogConflictServerLocalValidateStagePath(string $path): string
{
    if (preg_match(CATALOG_CONFLICT_SERVER_LOCAL_STAGE_PATTERN, $path) !== 1
        || str_contains($path, "\0") || str_contains($path, '/./') || str_contains($path, '/../')) {
        throw new InvalidArgumentException('Server-local preparation stage is outside the fixed boundary');
    }
    return $path;
}

/** @return array{admin_id:int,app_id:int,path_references:int,upload_id_references:int,upload_rows:int,media_attachment_rows:int} */
function catalogConflictServerLocalExpectedState(array $database, array $binding): array
{
    $required = ['path_references', 'upload_id_references', 'upload_rows', 'media_attachment_rows'];
    foreach ($required as $key) {
        if (!array_key_exists($key, $binding) || !is_int($binding[$key])) {
            throw new InvalidArgumentException('Server-local binding count is invalid');
        }
    }
    if ((int) ($database['path_references'] ?? -1) !== $binding['path_references']
        || (int) ($database['upload_id_references'] ?? -1) !== $binding['upload_id_references']
        || !is_array($database['uploads'] ?? null)
        || !is_array($database['attachments'] ?? null)
        || count($database['uploads']) !== $binding['upload_rows']
        || count($database['attachments']) !== $binding['media_attachment_rows']) {
        throw new RuntimeException('Server-local database reference counts changed');
    }

    $tenants = [];
    $referenceTenants = $database['reference_tenants'] ?? null;
    if (!is_array($referenceTenants) || $referenceTenants === []) {
        throw new RuntimeException('Server-local references do not prove one tenant');
    }
    $referenceCount = 0;
    foreach ($referenceTenants as $key => $count) {
        if (!is_string($key) || preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/D', $key) !== 1
            || !is_int($count) || $count < 1) {
            throw new RuntimeException('Server-local reference tenant evidence is invalid');
        }
        $tenants[$key] = true;
        $referenceCount += $count;
    }
    if ($referenceCount !== $binding['path_references'] + $binding['upload_id_references']) {
        throw new RuntimeException('Server-local tenant evidence count changed');
    }
    foreach (array_merge($database['uploads'], $database['attachments']) as $row) {
        if (!is_array($row)) throw new RuntimeException('Server-local tenant row is invalid');
        $adminId = filter_var($row['admin_id'] ?? null, FILTER_VALIDATE_INT);
        $appId = filter_var($row['app_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($adminId) || $adminId < 1 || !is_int($appId) || $appId < 1) {
            throw new RuntimeException('Server-local row tenant is invalid');
        }
        $tenants[$adminId . ':' . $appId] = true;
    }
    if (count($tenants) !== 1) throw new RuntimeException('Server-local evidence crosses a tenant boundary');
    $tenant = (string) array_key_first($tenants);
    [$adminText, $appText] = explode(':', $tenant, 2);
    $adminId = filter_var($adminText, FILTER_VALIDATE_INT);
    $appId = filter_var($appText, FILTER_VALIDATE_INT);
    if (!is_int($adminId) || !is_int($appId) || $adminId < 1 || $appId < 1) {
        throw new RuntimeException('Server-local tenant identifier is invalid');
    }
    return [
        'admin_id' => $adminId,
        'app_id' => $appId,
        'path_references' => $binding['path_references'],
        'upload_id_references' => $binding['upload_id_references'],
        'upload_rows' => $binding['upload_rows'],
        'media_attachment_rows' => $binding['media_attachment_rows'],
    ];
}

/** @return array{codec_name:string,width:int,height:int} */
function catalogConflictServerLocalParseProbe(string $json, array $binding): array
{
    if (strlen($json) < 2 || strlen($json) > 32768) {
        throw new RuntimeException('Server-local FFprobe receipt is outside its boundary');
    }
    $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    $streams = is_array($decoded) ? ($decoded['streams'] ?? null) : null;
    $allowedTop = ['streams' => true, 'programs' => true, 'stream_groups' => true];
    if (!is_array($streams) || !array_is_list($streams) || count($streams) !== 1
        || !is_array($streams[0]) || array_diff_key($decoded, $allowedTop) !== []
        || (isset($decoded['programs']) && $decoded['programs'] !== [])
        || (isset($decoded['stream_groups']) && $decoded['stream_groups'] !== [])) {
        throw new RuntimeException('Server-local FFprobe did not return exactly one selected stream');
    }
    $stream = $streams[0];
    if (array_diff_key($stream, ['codec_name' => true, 'codec_type' => true, 'width' => true, 'height' => true]) !== []
        || ($stream['codec_name'] ?? null) !== ($binding['codec_name'] ?? null)
        || ($stream['codec_type'] ?? null) !== 'video') {
        throw new RuntimeException('Server-local FFprobe codec contract changed');
    }
    $width = filter_var($stream['width'] ?? null, FILTER_VALIDATE_INT);
    $height = filter_var($stream['height'] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($width) || !is_int($height) || $width < 1 || $height < 1
        || $width > 8192 || $height > 8192 || $width * $height > 40000000
        || (is_int($binding['width'] ?? null) && $width !== $binding['width'])
        || (is_int($binding['height'] ?? null) && $height !== $binding['height'])) {
        throw new RuntimeException('Server-local FFprobe dimensions changed');
    }
    return ['codec_name' => (string) $stream['codec_name'], 'width' => $width, 'height' => $height];
}

/** @return list<string> */
function catalogConflictServerLocalProbeCommand(string $input): array
{
    if (preg_match(
        '#^/tmp/yiyunying-catalog-conflict-[0-9]{8}-[0-9]{6}-[0-9a-f]{16}/(jpeg|heic)-source[.]input$#D',
        $input
    ) !== 1) {
        throw new InvalidArgumentException('Server-local FFprobe input is outside the fixed stage');
    }
    return [
        CATALOG_CONFLICT_SERVER_LOCAL_FFPROBE,
        '-nostdin', '-v', 'error', '-protocol_whitelist', 'file',
        '-select_streams', 'v:0',
        '-show_entries', 'stream=codec_name,codec_type,width,height',
        '-of', 'json', $input,
    ];
}

/** @return list<string> */
function catalogConflictServerLocalConvertCommand(string $input, string $output): array
{
    $inputMatch = [];
    $outputMatch = [];
    if (preg_match(
        '#^/tmp/yiyunying-catalog-conflict-[0-9]{8}-[0-9]{6}-[0-9a-f]{16}/(jpeg|heic)-source[.]input$#D',
        $input,
        $inputMatch
    ) !== 1 || preg_match(
        '#^/tmp/yiyunying-catalog-conflict-[0-9]{8}-[0-9]{6}-[0-9a-f]{16}/(jpeg|heic)-ffmpeg[.]png[.]partial$#D',
        $output,
        $outputMatch
    ) !== 1 || ($inputMatch[1] ?? null) !== ($outputMatch[1] ?? null)
        || dirname($input) !== dirname($output)) {
        throw new InvalidArgumentException('Server-local FFmpeg paths are outside the fixed stage');
    }
    return [
        CATALOG_CONFLICT_SERVER_LOCAL_FFMPEG,
        '-nostdin', '-hide_banner', '-loglevel', 'error',
        '-protocol_whitelist', 'file', '-probesize', '1048576',
        '-analyzeduration', '5000000', '-i', $input,
        '-map', '0:v:0', '-frames:v', '1', '-an', '-sn', '-dn',
        '-vf', 'format=rgba', '-map_metadata', '-1', '-map_chapters', '-1',
        '-c:v', 'png', '-compression_level', '9', '-f', 'image2', '-update', '1', '-y', $output,
    ];
}

/** @return array{width:int,height:int,size_bytes:int,sha256:string} */
function catalogConflictServerLocalStripAncillaryPng(string $source, string $destination): array
{
    if ($source === $destination || file_exists($destination) || is_link($destination)) {
        throw new InvalidArgumentException('PNG sanitizer destination is not exclusive');
    }
    $sourceSize = @filesize($source);
    if (!is_int($sourceSize) || $sourceSize < 45 || $sourceSize > 512 * 1024 * 1024
        || !is_file($source) || is_link($source)) {
        throw new RuntimeException('FFmpeg PNG is outside the sanitizer boundary');
    }
    $input = @fopen($source, 'rb');
    $output = @fopen($destination, 'x+b');
    if ($input === false || $output === false) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        @unlink($destination);
        throw new RuntimeException('PNG sanitizer streams could not be opened');
    }
    $readExact = static function ($stream, int $length): string {
        $value = '';
        while (strlen($value) < $length) {
            $chunk = fread($stream, $length - strlen($value));
            if (!is_string($chunk) || $chunk === '') throw new RuntimeException('PNG sanitizer input ended early');
            $value .= $chunk;
        }
        return $value;
    };
    $writeExact = static function ($stream, string $bytes): void {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($stream, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) throw new RuntimeException('PNG sanitizer output write failed');
            $offset += $written;
        }
    };
    $width = 0;
    $height = 0;
    $seenIhdr = false;
    $seenIdat = false;
    $seenIend = false;
    $idatClosed = false;
    try {
        $signature = $readExact($input, 8);
        if ($signature !== "\x89PNG\r\n\x1a\n") throw new RuntimeException('FFmpeg output is not PNG');
        $writeExact($output, $signature);
        while (!$seenIend) {
            $lengthBytes = $readExact($input, 4);
            $lengthValue = unpack('Nlength', $lengthBytes);
            $length = is_array($lengthValue) ? (int) ($lengthValue['length'] ?? -1) : -1;
            $type = $readExact($input, 4);
            if ($length < 0 || $length > 512 * 1024 * 1024 || preg_match('/^[A-Za-z]{4}$/D', $type) !== 1) {
                throw new RuntimeException('PNG sanitizer chunk header is invalid');
            }
            $critical = (ord($type[0]) & 0x20) === 0;
            if ($critical && !in_array($type, ['IHDR', 'IDAT', 'IEND'], true)) {
                throw new RuntimeException('PNG sanitizer found an unexpected critical chunk');
            }
            if (!$seenIhdr && $type !== 'IHDR') throw new RuntimeException('PNG IHDR is not first');
            if ($type === 'IHDR' && ($seenIhdr || $length !== 13)) throw new RuntimeException('PNG IHDR is invalid');
            if ($type === 'IDAT' && (!$seenIhdr || $seenIend || $idatClosed)) {
                throw new RuntimeException('PNG IDAT order is invalid');
            }
            if ($seenIdat && $type !== 'IDAT' && $type !== 'IEND' && $critical) {
                throw new RuntimeException('PNG critical data after IDAT is invalid');
            }
            if ($type === 'IEND' && (!$seenIdat || $length !== 0)) throw new RuntimeException('PNG IEND is invalid');
            $retain = in_array($type, ['IHDR', 'IDAT', 'IEND'], true);
            if ($retain) {
                $writeExact($output, $lengthBytes);
                $writeExact($output, $type);
            }
            $hash = hash_init('crc32b');
            hash_update($hash, $type);
            $remaining = $length;
            $ihdr = '';
            while ($remaining > 0) {
                $data = $readExact($input, min(1024 * 1024, $remaining));
                hash_update($hash, $data);
                if ($type === 'IHDR') $ihdr .= $data;
                if ($retain) $writeExact($output, $data);
                $remaining -= strlen($data);
            }
            $crc = $readExact($input, 4);
            $expectedCrc = pack('H*', hash_final($hash));
            if (!hash_equals($expectedCrc, $crc)) throw new RuntimeException('PNG sanitizer CRC validation failed');
            if ($retain) $writeExact($output, $crc);
            if ($type === 'IHDR') {
                $header = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $ihdr);
                if (!is_array($header) || (int) ($header['depth'] ?? 0) !== 8
                    || (int) ($header['color'] ?? -1) !== 6 || (int) ($header['compression'] ?? -1) !== 0
                    || (int) ($header['filter'] ?? -1) !== 0 || !in_array((int) ($header['interlace'] ?? -1), [0, 1], true)) {
                    throw new RuntimeException('FFmpeg PNG is not reviewed 8-bit RGBA');
                }
                $width = (int) ($header['width'] ?? 0);
                $height = (int) ($header['height'] ?? 0);
                if ($width < 1 || $height < 1 || $width > 8192 || $height > 8192
                    || $width * $height > 40000000) {
                    throw new RuntimeException('FFmpeg PNG dimensions are outside the boundary');
                }
                $seenIhdr = true;
            } elseif ($type === 'IDAT') {
                $seenIdat = true;
            } elseif ($type === 'IEND') {
                $seenIend = true;
            } elseif ($seenIdat) {
                $idatClosed = true;
            }
        }
        if (fread($input, 1) !== '') throw new RuntimeException('PNG sanitizer found trailing bytes');
        if (!fflush($output) || (function_exists('fsync') && !fsync($output))) {
            throw new RuntimeException('PNG sanitizer output was not durable');
        }
    } catch (Throwable $exception) {
        fclose($input);
        fclose($output);
        @unlink($destination);
        throw $exception;
    }
    fclose($input);
    fclose($output);
    if (!@chmod($destination, 0600)) {
        @unlink($destination);
        throw new RuntimeException('PNG sanitizer output mode failed');
    }
    $size = @filesize($destination);
    $sha256 = @hash_file('sha256', $destination);
    if (!is_int($size) || $size < 1 || $size > 512 * 1024 * 1024 || !is_string($sha256)) {
        @unlink($destination);
        throw new RuntimeException('PNG sanitizer fingerprint failed');
    }
    return ['width' => $width, 'height' => $height, 'size_bytes' => $size, 'sha256' => strtolower($sha256)];
}

/** @return array{stdout:string,stderr:string} */
function catalogConflictServerLocalRun(array $command, int $timeoutSeconds, int $maximumOutput = 65536): array
{
    if ($command === [] || !is_string($command[0]) || !in_array($command[0], [
        CATALOG_CONFLICT_SERVER_LOCAL_FFMPEG,
        CATALOG_CONFLICT_SERVER_LOCAL_FFPROBE,
    ], true)) {
        throw new InvalidArgumentException('Only the fixed media runtime may be executed');
    }
    foreach ($command as $argument) {
        if (!is_string($argument) || str_contains($argument, "\0") || strlen($argument) > 4096) {
            throw new InvalidArgumentException('Media runtime argument is invalid');
        }
    }
    if ($timeoutSeconds < 1 || $timeoutSeconds > 90 || $maximumOutput < 1024 || $maximumOutput > 262144) {
        throw new InvalidArgumentException('Media runtime process limits are invalid');
    }
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $environment = ['PATH' => '/usr/bin:/bin', 'HOME' => '/nonexistent', 'LC_ALL' => 'C', 'LANG' => 'C'];
    $pipes = [];
    $process = @proc_open($command, $descriptors, $pipes, null, $environment, ['bypass_shell' => true]);
    if (!is_resource($process) || count($pipes) !== 3) throw new RuntimeException('Media runtime process could not start');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + $timeoutSeconds;
    $exitCode = -1;
    try {
        while (true) {
            foreach ([[1, &$stdout], [2, &$stderr]] as &$stream) {
                $chunk = fread($pipes[$stream[0]], 8192);
                if (is_string($chunk) && $chunk !== '') $stream[1] .= $chunk;
            }
            unset($stream);
            if (strlen($stdout) + strlen($stderr) > $maximumOutput) {
                throw new RuntimeException('Media runtime output exceeded its limit');
            }
            $status = proc_get_status($process);
            if (!is_array($status)) throw new RuntimeException('Media runtime status is unavailable');
            if (!($status['running'] ?? false)) {
                $exitCode = (int) ($status['exitcode'] ?? -1);
                break;
            }
            if (microtime(true) >= $deadline) throw new RuntimeException('Media runtime timed out');
            usleep(10000);
        }
        foreach ([1 => &$stdout, 2 => &$stderr] as $index => &$target) {
            while (!feof($pipes[$index])) {
                $chunk = fread($pipes[$index], 8192);
                if (!is_string($chunk) || $chunk === '') break;
                $target .= $chunk;
                if (strlen($stdout) + strlen($stderr) > $maximumOutput) {
                    throw new RuntimeException('Media runtime output exceeded its limit');
                }
            }
        }
        unset($target);
    } catch (Throwable $exception) {
        @proc_terminate($process, 9);
        throw $exception;
    } finally {
        foreach ([1, 2] as $index) if (is_resource($pipes[$index])) fclose($pipes[$index]);
        $closed = proc_close($process);
        if ($exitCode < 0 && is_int($closed)) $exitCode = $closed;
    }
    if ($exitCode !== 0) throw new RuntimeException('Media runtime command failed');
    return ['stdout' => $stdout, 'stderr' => $stderr];
}

/** @return array{ffmpeg:string,ffprobe:string} */
function catalogConflictServerLocalAssertRuntime(): array
{
    if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        throw new RuntimeException('Server-local media preparation requires Linux root');
    }
    foreach (['/', '/opt', '/opt/yiyunying', CATALOG_CONFLICT_SERVER_LOCAL_RUNTIME_ROOT] as $directory) {
        $stat = @lstat($directory);
        if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0040000
            || is_link($directory) || (int) ($stat['uid'] ?? -1) !== 0 || (int) ($stat['gid'] ?? -1) !== 0
            || (((int) ($stat['mode'] ?? 0)) & 0022) !== 0) {
            throw new RuntimeException('Media runtime ancestor is not root controlled');
        }
    }
    $current = CATALOG_CONFLICT_SERVER_LOCAL_RUNTIME_ROOT . '/current';
    $currentStat = @lstat($current);
    $target = @readlink($current);
    if (!is_array($currentStat) || !is_link($current) || $target !== CATALOG_CONFLICT_SERVER_LOCAL_RUNTIME_VERSION
        || (int) ($currentStat['uid'] ?? -1) !== 0 || (int) ($currentStat['gid'] ?? -1) !== 0
        || (int) ($currentStat['nlink'] ?? 0) !== 1) {
        throw new RuntimeException('Media runtime current link is not pinned');
    }
    $version = CATALOG_CONFLICT_SERVER_LOCAL_RUNTIME_ROOT . '/' . CATALOG_CONFLICT_SERVER_LOCAL_RUNTIME_VERSION;
    $versionStat = @lstat($version);
    if (!is_array($versionStat) || is_link($version) || !is_dir($version)
        || (int) ($versionStat['uid'] ?? -1) !== 0 || (int) ($versionStat['gid'] ?? -1) !== 0
        || (((int) ($versionStat['mode'] ?? 0)) & 0777) !== 0555) {
        throw new RuntimeException('Media runtime version directory is not immutable');
    }
    $contracts = [
        'ffmpeg' => [CATALOG_CONFLICT_SERVER_LOCAL_FFMPEG_SIZE, CATALOG_CONFLICT_SERVER_LOCAL_FFMPEG_SHA256],
        'ffprobe' => [CATALOG_CONFLICT_SERVER_LOCAL_FFPROBE_SIZE, CATALOG_CONFLICT_SERVER_LOCAL_FFPROBE_SHA256],
    ];
    $result = [];
    foreach ($contracts as $name => [$expectedSize, $expectedHash]) {
        $stable = CATALOG_CONFLICT_SERVER_LOCAL_RUNTIME_ROOT . '/current/' . $name;
        $pinned = $version . '/' . $name;
        $resolved = @realpath($stable);
        $stat = @lstat($pinned);
        $hash = @hash_file('sha256', $pinned);
        if ($resolved !== $pinned || !is_array($stat) || is_link($pinned) || !is_file($pinned)
            || (int) ($stat['uid'] ?? -1) !== 0 || (int) ($stat['gid'] ?? -1) !== 0
            || (int) ($stat['nlink'] ?? 0) !== 1 || (((int) ($stat['mode'] ?? 0)) & 0777) !== 0555
            || (int) ($stat['size'] ?? -1) !== $expectedSize || !is_string($hash)
            || !hash_equals($expectedHash, strtolower($hash))) {
            throw new RuntimeException('Media runtime binary does not match the reviewed artifact');
        }
        $result[$name] = $stable;
    }
    $ffmpegVersion = catalogConflictServerLocalRun([$result['ffmpeg'], '-version'], 10, 32768)['stdout'];
    $ffprobeVersion = catalogConflictServerLocalRun([$result['ffprobe'], '-version'], 10, 32768)['stdout'];
    if (!str_starts_with($ffmpegVersion, 'ffmpeg version 8.1.2')
        || !str_starts_with($ffprobeVersion, 'ffprobe version 8.1.2')) {
        throw new RuntimeException('Media runtime version output is not reviewed');
    }
    return $result;
}

/** @param array<string,array{database:array,replacement:array}> $artifacts */
function catalogConflictServerLocalBuildSourcePlan(string $batch, array $artifacts): array
{
    $batch = catalogConflictRepairValidateBatch($batch);
    $bindings = catalogConflictServerLocalBindings();
    if (array_diff_key($artifacts, $bindings) !== [] || array_diff_key($bindings, $artifacts) !== []) {
        throw new InvalidArgumentException('Server-local artifacts do not contain both fixed actions');
    }
    $items = [];
    foreach ($bindings as $action => $binding) {
        $artifact = $artifacts[$action];
        if (!is_array($artifact['database'] ?? null) || !is_array($artifact['replacement'] ?? null)) {
            throw new InvalidArgumentException('Server-local prepared artifact is incomplete');
        }
        $expected = catalogConflictServerLocalExpectedState($artifact['database'], $binding);
        $items[] = [
            'path_sha256' => $binding['path_sha256'],
            'preimage' => $binding['preimage'],
            'replacement' => $artifact['replacement'],
            'expected' => $expected,
            'action' => $action,
            'registration' => $binding['registration'],
        ];
    }
    return catalogConflictRepairValidateSourcePlan([
        'schema' => 2,
        'plan_kind' => 'source',
        'batch' => $batch,
        'items' => $items,
    ]);
}
