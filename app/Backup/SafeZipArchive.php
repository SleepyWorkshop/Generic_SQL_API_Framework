<?php

final class SafeZipArchive
{
    public const MAX_ARCHIVE_BYTES = 25 * 1024 * 1024;
    public const MAX_EXTRACTED_BYTES = 10 * 1024 * 1024;
    public const MAX_ENTRY_BYTES = 5 * 1024 * 1024;
    public const MAX_ENTRIES = 20;

    public static function create(string $path, array $entries): void
    {
        if ($entries === [] || count($entries) > self::MAX_ENTRIES) {
            throw new RuntimeException('Backup ZIP entry count is invalid.');
        }
        $stream = @fopen($path, 'x+b');
        if ($stream === false) throw new RuntimeException('Backup ZIP could not be created.');
        $complete = false;
        try {
            @chmod($path, 0600);
            $central = '';
            $offset = 0;
            $total = 0;
            foreach ($entries as $name => $contents) {
                self::validateName($name);
                if (!is_string($contents)) throw new RuntimeException('Backup ZIP content is invalid.');
                $size = strlen($contents);
                $total += $size;
                if ($size > self::MAX_ENTRY_BYTES || $total > self::MAX_EXTRACTED_BYTES) {
                    throw new RuntimeException('Backup ZIP exceeds the safe size limit.');
                }
                $crc = self::unsignedCrc32($contents);
                $nameLength = strlen($name);
                $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0)
                    . $name . $contents;
                self::writeAll($stream, $local);
                $central .= pack(
                    'VvvvvvvVVVvvvvvVV',
                    0x02014b50, 0x0314, 20, 0, 0, 0, 0, $crc, $size, $size,
                    $nameLength, 0, 0, 0, 0, 0100600 << 16, $offset
                ) . $name;
                $offset += strlen($local);
            }
            self::writeAll($stream, $central);
            self::writeAll($stream, pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($central), $offset, 0));
            if (!fflush($stream)) throw new RuntimeException('Backup ZIP flush failed.');
            clearstatcache(true, $path);
            $archiveSize = @filesize($path);
            if (!is_int($archiveSize) || $archiveSize <= 22 || $archiveSize > self::MAX_ARCHIVE_BYTES) {
                throw new RuntimeException('Backup ZIP size is invalid.');
            }
            $complete = true;
        } finally {
            fclose($stream);
            if (!$complete) @unlink($path);
        }
    }

    public static function read(string $path): array
    {
        clearstatcache(true, $path);
        $size = @filesize($path);
        if (!is_int($size) || $size < 22 || $size > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('Backup ZIP is missing, malformed, or too large.');
        }
        $archive = @file_get_contents($path);
        if (!is_string($archive) || strlen($archive) !== $size) {
            throw new RuntimeException('Backup ZIP could not be read.');
        }
        $searchStart = max(0, $size - 65557);
        $eocdOffset = strrpos(substr($archive, $searchStart), "PK\x05\x06");
        if ($eocdOffset === false) throw new RuntimeException('Backup ZIP end record is invalid.');
        $eocdOffset += $searchStart;
        $eocd = self::unpackAt('Vsignature/vdisk/vcentralDisk/ventriesDisk/ventries/VcentralSize/VcentralOffset/vcommentLength', $archive, $eocdOffset, 22);
        if ($eocd['signature'] !== 0x06054b50 || $eocd['disk'] !== 0 || $eocd['centralDisk'] !== 0
            || $eocd['entriesDisk'] !== $eocd['entries'] || $eocd['entries'] < 1
            || $eocd['entries'] > self::MAX_ENTRIES || $eocdOffset + 22 + $eocd['commentLength'] !== $size
            || $eocd['centralOffset'] + $eocd['centralSize'] !== $eocdOffset) {
            throw new RuntimeException('Backup ZIP structure is invalid.');
        }

        $entries = [];
        $cursor = $eocd['centralOffset'];
        $total = 0;
        for ($index = 0; $index < $eocd['entries']; $index++) {
            $central = self::unpackAt(
                'Vsignature/vmadeBy/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/VlocalOffset',
                $archive,
                $cursor,
                46
            );
            if ($central['signature'] !== 0x02014b50 || $central['disk'] !== 0 || $central['flags'] !== 0
                || $central['method'] !== 0 || $central['compressed'] !== $central['uncompressed']
                || $central['uncompressed'] > self::MAX_ENTRY_BYTES) {
                throw new RuntimeException('Backup ZIP contains an unsupported or unsafe entry.');
            }
            $recordLength = 46 + $central['nameLength'] + $central['extraLength'] + $central['commentLength'];
            if ($cursor + $recordLength > $eocdOffset) throw new RuntimeException('Backup ZIP central directory is truncated.');
            $name = substr($archive, $cursor + 46, $central['nameLength']);
            self::validateName($name);
            if (array_key_exists($name, $entries)) throw new RuntimeException('Backup ZIP contains duplicate entries.');
            $mode = ($central['external'] >> 16) & 0170000;
            if ($mode === 0120000 || $mode === 0060000) throw new RuntimeException('Backup ZIP contains an unsafe linked entry.');

            $local = self::unpackAt('Vsignature/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnameLength/vextraLength', $archive, $central['localOffset'], 30);
            $dataOffset = $central['localOffset'] + 30 + $local['nameLength'] + $local['extraLength'];
            if ($local['signature'] !== 0x04034b50 || $local['flags'] !== 0 || $local['method'] !== 0
                || $local['crc'] !== $central['crc'] || $local['compressed'] !== $central['compressed']
                || $local['uncompressed'] !== $central['uncompressed']
                || substr($archive, $central['localOffset'] + 30, $local['nameLength']) !== $name
                || $dataOffset + $central['compressed'] > $eocd['centralOffset']) {
                throw new RuntimeException('Backup ZIP local entry is invalid.');
            }
            $contents = substr($archive, $dataOffset, $central['compressed']);
            if (self::unsignedCrc32($contents) !== $central['crc']) throw new RuntimeException('Backup ZIP entry checksum is invalid.');
            $total += strlen($contents);
            if ($total > self::MAX_EXTRACTED_BYTES) throw new RuntimeException('Backup ZIP exceeds the safe extraction limit.');
            $entries[$name] = $contents;
            $cursor += $recordLength;
        }
        if ($cursor !== $eocdOffset) throw new RuntimeException('Backup ZIP central directory contains trailing data.');
        return $entries;
    }

    private static function validateName(mixed $name): void
    {
        if (!is_string($name) || $name === '' || strlen($name) > 240 || str_contains($name, "\0")
            || str_contains($name, '\\') || str_starts_with($name, '/') || str_starts_with($name, '//')
            || preg_match('/^[A-Za-z]:/', $name) === 1 || preg_match('#(^|/)\.\.(/|$)#', $name) === 1
            || preg_match('#(^|/)\.(/|$)#', $name) === 1 || str_ends_with($name, '/')) {
            throw new RuntimeException('Backup ZIP contains an unsafe entry name.');
        }
    }

    private static function unsignedCrc32(string $contents): int
    {
        $value = unpack('Nvalue', hash('crc32b', $contents, true));
        return (int)$value['value'];
    }

    private static function unpackAt(string $format, string $archive, int $offset, int $length): array
    {
        if ($offset < 0 || $offset + $length > strlen($archive)) throw new RuntimeException('Backup ZIP record is truncated.');
        $value = unpack($format, substr($archive, $offset, $length));
        if (!is_array($value)) throw new RuntimeException('Backup ZIP record is invalid.');
        return $value;
    }

    private static function writeAll($stream, string $contents): void
    {
        $written = 0;
        while ($written < strlen($contents)) {
            $bytes = fwrite($stream, substr($contents, $written));
            if ($bytes === false || $bytes === 0) throw new RuntimeException('Backup ZIP write failed.');
            $written += $bytes;
        }
    }
}
