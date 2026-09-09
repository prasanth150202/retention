<?php
/**
 * Minimal MaxMind DB (.mmdb) reader.
 *
 * Resolves an IP to country / region / city / coordinates. Works with any
 * MMDB-format city database; we ship DB-IP City Lite, which is the same
 * format as MaxMind GeoLite2 but downloadable without an account.
 *
 * WHY THIS IS HAND-WRITTEN
 * There is no composer install on the server, so the official reader would
 * have to be vendored — a thousand lines of third-party code with no update
 * path. The format is small, stable and fully specified, and the only query
 * we make is a single city lookup. Roughly 250 lines we can debug beats a
 * dependency we cannot patch.
 *
 * WHY IT NEVER THROWS AT THE CALLER
 * Geography is an enrichment, not the point. A missing, truncated or
 * unreadable database returns null and the event stores without a location.
 * Losing behavioural data because a geo file was stale would be a terrible
 * trade — and unlike orders, pixel events cannot be re-fetched.
 *
 * The IP is used for the lookup and discarded. There is no ip column in the
 * events table; only the resulting geo_id is stored.
 *
 * Format reference: https://maxmind.github.io/MaxMind-DB/
 */

declare(strict_types=1);

final class GeoIp
{
    private const METADATA_MARKER = "\xAB\xCD\xEFMaxMind.com";
    private const DATA_SEPARATOR  = 16;

    private static ?self $instance = null;
    private static bool $attempted = false;

    /** @var resource */
    private $fh;
    private int $nodeCount;
    private int $recordSize;
    private int $nodeByteSize;
    private int $searchTreeSize;
    private int $ipVersion;

    /** @var array<string,array|null> */
    private array $cache = [];

    private function __construct($fh, array $meta)
    {
        $this->fh             = $fh;
        $this->nodeCount      = (int) $meta['node_count'];
        $this->recordSize     = (int) $meta['record_size'];
        $this->ipVersion      = (int) ($meta['ip_version'] ?? 6);
        $this->nodeByteSize   = intdiv($this->recordSize, 4);
        $this->searchTreeSize = $this->nodeCount * $this->nodeByteSize;
    }

    /**
     * Look up an IP.
     *
     * @return array{country:?string,region:?string,city:?string,lat:?float,lon:?float}|null
     */
    public static function lookup(string $ip): ?array
    {
        $reader = self::reader();
        if ($reader === null || $ip === '') {
            return null;
        }

        try {
            return $reader->find($ip);
        } catch (Throwable) {
            return null;
        }
    }

    public static function available(): bool
    {
        return self::reader() !== null;
    }

    /** Path and size, for the setup page and health checks. */
    public static function describe(): array
    {
        $path = (string) Config::get('paths.geoip');

        return [
            'path'   => $path,
            'exists' => is_file($path),
            'bytes'  => is_file($path) ? (int) filesize($path) : 0,
            'usable' => self::available(),
        ];
    }

    private static function reader(): ?self
    {
        if (self::$attempted) {
            return self::$instance;
        }
        self::$attempted = true;

        $path = (string) Config::get('paths.geoip');

        if ($path === '' || !is_file($path) || filesize($path) < 1024) {
            return null;
        }

        try {
            $fh = @fopen($path, 'rb');
            if ($fh === false) {
                return null;
            }
            $meta = self::readMetadata($fh, (int) filesize($path));
            if ($meta === null || !isset($meta['node_count'], $meta['record_size'])) {
                fclose($fh);
                return null;
            }
            return self::$instance = new self($fh, $meta);
        } catch (Throwable) {
            return null;
        }
    }

    // -----------------------------------------------------------------
    // Metadata
    // -----------------------------------------------------------------

    private static function readMetadata($fh, int $size): ?array
    {
        // The metadata map follows the last occurrence of the marker. It lives
        // in the final 128 KB by specification.
        $tailLen = min($size, 128 * 1024);
        fseek($fh, $size - $tailLen);
        $tail = (string) fread($fh, $tailLen);

        $pos = strrpos($tail, self::METADATA_MARKER);
        if ($pos === false) {
            return null;
        }

        $start = $size - $tailLen + $pos + strlen(self::METADATA_MARKER);

        $decoder = new self_Decoder($fh, 0);
        [$meta] = $decoder->decode($start);

        return is_array($meta) ? $meta : null;
    }

    // -----------------------------------------------------------------
    // Lookup
    // -----------------------------------------------------------------

    private function find(string $ip): ?array
    {
        if (isset($this->cache[$ip])) {
            return $this->cache[$ip];
        }
        if (count($this->cache) > 10000) {
            $this->cache = [];
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return $this->cache[$ip] = null;
        }

        $bytes = unpack('C*', $packed) ?: [];
        $bits  = count($bytes) * 8;

        $node = 0;

        // An IPv4 address in an IPv6 tree starts 96 bits in, where the
        // ::ffff:0:0/96 mapping begins.
        if ($bits === 32 && $this->ipVersion === 6) {
            $node = $this->ipv4StartNode();
            if ($node === null) {
                return $this->cache[$ip] = null;
            }
        }

        for ($i = 0; $i < $bits; $i++) {
            if ($node >= $this->nodeCount) {
                break;
            }
            $byte = $bytes[intdiv($i, 8) + 1];
            $bit  = ($byte >> (7 - ($i % 8))) & 1;
            $node = $this->readNode($node, $bit);
        }

        if ($node === $this->nodeCount) {
            return $this->cache[$ip] = null;   // explicit "no data"
        }
        if ($node < $this->nodeCount) {
            return $this->cache[$ip] = null;   // ran out of bits
        }

        $offset = $node - $this->nodeCount - self::DATA_SEPARATOR
                + $this->searchTreeSize + self::DATA_SEPARATOR;

        $decoder = new self_Decoder($this->fh, $this->searchTreeSize + self::DATA_SEPARATOR);
        [$data]  = $decoder->decode($offset);

        return $this->cache[$ip] = is_array($data) ? $this->shape($data) : null;
    }

    /** Cached walk of 96 zero bits, done once per process. */
    private function ipv4StartNode(): ?int
    {
        static $start = null;
        if ($start !== null) {
            return $start;
        }

        $node = 0;
        for ($i = 0; $i < 96; $i++) {
            if ($node >= $this->nodeCount) {
                return null;
            }
            $node = $this->readNode($node, 0);
        }

        return $start = $node;
    }

    /** Read the left (0) or right (1) record of a node. */
    private function readNode(int $node, int $index): int
    {
        fseek($this->fh, $node * $this->nodeByteSize);
        $raw = (string) fread($this->fh, $this->nodeByteSize);
        $b   = unpack('C*', $raw) ?: [];

        return match ($this->recordSize) {
            24 => $index === 0
                ? ($b[1] << 16) | ($b[2] << 8) | $b[3]
                : ($b[4] << 16) | ($b[5] << 8) | $b[6],

            // 28-bit records share the middle byte: high nibble belongs to the
            // left record, low nibble to the right.
            28 => $index === 0
                ? (($b[4] & 0xF0) << 20) | ($b[1] << 16) | ($b[2] << 8) | $b[3]
                : (($b[4] & 0x0F) << 24) | ($b[5] << 16) | ($b[6] << 8) | $b[7],

            32 => $index === 0
                ? ($b[1] << 24) | ($b[2] << 16) | ($b[3] << 8) | $b[4]
                : ($b[5] << 24) | ($b[6] << 16) | ($b[7] << 8) | $b[8],

            default => throw new RuntimeException("Unsupported record size {$this->recordSize}"),
        };
    }

    /** Flatten the GeoIP2 City structure to the five fields we store. */
    private function shape(array $d): array
    {
        $name = static function ($node): ?string {
            if (!is_array($node) || !isset($node['names']) || !is_array($node['names'])) {
                return null;
            }
            $n = $node['names']['en'] ?? reset($node['names']);
            return is_string($n) && $n !== '' ? $n : null;
        };

        $region = null;
        if (isset($d['subdivisions'][0])) {
            $region = $name($d['subdivisions'][0]);
        }

        return [
            'country' => isset($d['country']['iso_code']) ? (string) $d['country']['iso_code'] : null,
            'region'  => $region,
            'city'    => $name($d['city'] ?? null),
            'lat'     => isset($d['location']['latitude'])  ? (float) $d['location']['latitude']  : null,
            'lon'     => isset($d['location']['longitude']) ? (float) $d['location']['longitude'] : null,
        ];
    }
}

/**
 * MMDB data-section decoder.
 *
 * Separate class purely to keep the reader readable; not part of the public
 * surface, hence the unconventional name rather than a second file.
 */
final class self_Decoder
{
    private const TYPE_EXTENDED = 0;
    private const TYPE_POINTER  = 1;
    private const TYPE_UTF8     = 2;
    private const TYPE_DOUBLE   = 3;
    private const TYPE_BYTES    = 4;
    private const TYPE_UINT16   = 5;
    private const TYPE_UINT32   = 6;
    private const TYPE_MAP      = 7;
    private const TYPE_INT32    = 8;
    private const TYPE_UINT64   = 9;
    private const TYPE_UINT128  = 10;
    private const TYPE_ARRAY    = 11;
    private const TYPE_CONTAINER = 12;
    private const TYPE_END      = 13;
    private const TYPE_BOOL     = 14;
    private const TYPE_FLOAT    = 15;

    /** @param resource $fh */
    public function __construct(private $fh, private int $dataStart)
    {
    }

    /**
     * Decode the value at an absolute file offset.
     *
     * @return array{0:mixed,1:int} value and the offset just past it
     */
    public function decode(int $offset): array
    {
        $ctrl = $this->byteAt($offset);
        $offset++;

        $type = $ctrl >> 5;

        if ($type === self::TYPE_EXTENDED) {
            $type = $this->byteAt($offset) + 7;
            $offset++;
        }

        if ($type === self::TYPE_POINTER) {
            [$target, $offset] = $this->decodePointer($ctrl, $offset);
            [$value]           = $this->decode($target);
            return [$value, $offset];
        }

        [$size, $offset] = $this->decodeSize($ctrl, $offset);

        return match ($type) {
            self::TYPE_MAP       => $this->decodeMap($size, $offset),
            self::TYPE_ARRAY     => $this->decodeArray($size, $offset),
            self::TYPE_UTF8      => [$size === 0 ? '' : $this->read($offset, $size), $offset + $size],
            self::TYPE_BYTES     => [$this->read($offset, $size), $offset + $size],
            self::TYPE_UINT16,
            self::TYPE_UINT32,
            self::TYPE_UINT64    => [$this->decodeUint($offset, $size), $offset + $size],
            self::TYPE_INT32     => [$this->decodeInt32($offset, $size), $offset + $size],
            self::TYPE_DOUBLE    => [$this->decodeDouble($offset, $size), $offset + $size],
            self::TYPE_FLOAT     => [$this->decodeFloat($offset, $size), $offset + $size],
            self::TYPE_BOOL      => [$size !== 0, $offset],
            self::TYPE_UINT128   => [$this->read($offset, $size), $offset + $size],
            self::TYPE_CONTAINER,
            self::TYPE_END       => [null, $offset],
            default              => [null, $offset],
        };
    }

    private function decodePointer(int $ctrl, int $offset): array
    {
        $size = ($ctrl >> 3) & 0x3;
        $high = $ctrl & 0x7;

        return match ($size) {
            0 => [$this->dataStart + (($high << 8) | $this->byteAt($offset)), $offset + 1],
            1 => [$this->dataStart + ((($high << 16) | $this->uintAt($offset, 2)) + 2048), $offset + 2],
            2 => [$this->dataStart + ((($high << 24) | $this->uintAt($offset, 3)) + 526336), $offset + 3],
            default => [$this->dataStart + $this->uintAt($offset, 4), $offset + 4],
        };
    }

    private function decodeSize(int $ctrl, int $offset): array
    {
        $size = $ctrl & 0x1f;

        return match (true) {
            $size < 29  => [$size, $offset],
            $size === 29 => [29 + $this->byteAt($offset), $offset + 1],
            $size === 30 => [285 + $this->uintAt($offset, 2), $offset + 2],
            default      => [65821 + $this->uintAt($offset, 3), $offset + 3],
        };
    }

    private function decodeMap(int $size, int $offset): array
    {
        $out = [];
        for ($i = 0; $i < $size; $i++) {
            [$key, $offset]   = $this->decode($offset);
            [$value, $offset] = $this->decode($offset);
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }
        return [$out, $offset];
    }

    private function decodeArray(int $size, int $offset): array
    {
        $out = [];
        for ($i = 0; $i < $size; $i++) {
            [$value, $offset] = $this->decode($offset);
            $out[] = $value;
        }
        return [$out, $offset];
    }

    private function decodeUint(int $offset, int $size): int
    {
        return $size === 0 ? 0 : $this->uintAt($offset, $size);
    }

    private function decodeInt32(int $offset, int $size): int
    {
        if ($size === 0) {
            return 0;
        }
        $v = $this->uintAt($offset, $size);
        // Sign-extend when the full four bytes are present.
        return ($size === 4 && $v & 0x80000000) ? $v - 0x100000000 : $v;
    }

    private function decodeDouble(int $offset, int $size): float
    {
        $u = unpack('E', $this->read($offset, 8));
        return $u ? (float) $u[1] : 0.0;
    }

    private function decodeFloat(int $offset, int $size): float
    {
        $u = unpack('G', $this->read($offset, 4));
        return $u ? (float) $u[1] : 0.0;
    }

    private function read(int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        fseek($this->fh, $offset);
        return (string) fread($this->fh, $length);
    }

    private function byteAt(int $offset): int
    {
        $b = unpack('C', $this->read($offset, 1));
        return $b ? (int) $b[1] : 0;
    }

    private function uintAt(int $offset, int $length): int
    {
        $bytes = unpack('C*', $this->read($offset, $length)) ?: [];
        $value = 0;
        foreach ($bytes as $b) {
            $value = ($value << 8) | $b;
        }
        return $value;
    }
}
