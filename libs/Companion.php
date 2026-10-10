<?php

declare(strict_types=1);

/**
 * Companion-Protokoll des Apple TV (das, was auch die Fernbedienung im
 * iPhone benutzt): Suche per mDNS, HAP-Kopplung, Pair-Verify und
 * verschluesselte Befehle.
 *
 * Portiert aus pyatv (https://github.com/postlund/pyatv, MIT-Lizenz,
 * Copyright (c) 2020 Pierre Staahl), nur der benoetigte Teil:
 * OPACK, TLV8, HAP-Kopplung (SRP-6a, 3072 Bit, SHA-512), Pair-Verify
 * (X25519, Ed25519) und die Rahmen mit ChaCha20-Poly1305.
 *
 * SRP, OPACK und TLV8 sind mit festen Testwerten gegen die
 * Originalbibliotheken (srptools, pyatv) geprueft: identische Ergebnisse.
 *
 * Braucht die PHP-Erweiterungen sodium, gmp und sockets.
 */

namespace SymconAppleTV;

use Exception;
use GMP;

/** Binaerdaten (in PHP sonst nicht von Text zu unterscheiden). */
final class Bytes
{
    public function __construct(public string $b)
    {
    }
}

final class Opack
{
    public static function pack($d): string
    {
        if ($d === null) {
            return "\x04";
        }
        if (is_bool($d)) {
            return $d ? "\x01" : "\x02";
        }
        if (is_int($d)) {
            if ($d >= 0 && $d < 0x28) {
                return chr($d + 8);
            }
            if ($d >= 0 && $d <= 0xff) {
                return "\x30" . chr($d);
            }
            if ($d >= 0 && $d <= 0xffff) {
                return "\x31" . pack('v', $d);
            }
            if ($d >= 0 && $d <= 0xffffffff) {
                return "\x32" . pack('V', $d);
            }
            return "\x33" . pack('P', $d);
        }
        if (is_float($d)) {
            return "\x36" . pack('e', $d);
        }
        if ($d instanceof Bytes) {
            $n = strlen($d->b);
            if ($n <= 0x20) {
                return chr(0x70 + $n) . $d->b;
            }
            if ($n <= 0xff) {
                return "\x91" . chr($n) . $d->b;
            }
            if ($n <= 0xffff) {
                return "\x92" . pack('v', $n) . $d->b;
            }
            return "\x93" . pack('V', $n) . $d->b;
        }
        if (is_string($d)) {
            $n = strlen($d);
            if ($n <= 0x20) {
                return chr(0x40 + $n) . $d;
            }
            if ($n <= 0xff) {
                return "\x61" . chr($n) . $d;
            }
            if ($n <= 0xffff) {
                return "\x62" . pack('v', $n) . $d;
            }
            return "\x64" . pack('V', $n) . $d;
        }
        if (is_array($d)) {
            // Leeres Array gilt als Woerterbuch ({} kommt vor, [] nicht)
            $liste = $d !== [] && array_is_list($d);
            $n = count($d);
            $r = chr(($liste ? 0xd0 : 0xe0) + min($n, 0xf));
            foreach ($d as $k => $v) {
                $r .= ($liste ? '' : self::pack((string) $k)) . self::pack($v);
            }
            return $n >= 0xf ? $r . "\x03" : $r;
        }
        throw new Exception('OPACK: Typ ' . gettype($d) . ' nicht unterstuetzt');
    }

    public static function unpack(string $d, int &$p = 0, array &$objekte = [])
    {
        $t = ord($d[$p++]);
        $merken = true;
        if ($t == 0x01 || $t == 0x02 || $t == 0x04) {
            $merken = false;
            $wert = $t == 0x01 ? true : ($t == 0x02 ? false : null);
        } elseif ($t == 0x05) {
            $wert = new Bytes(substr($d, $p, 16));
            $p += 16;
        } elseif ($t == 0x06) {
            $wert = unpack('P', substr($d, $p, 8))[1];
            $p += 8;
        } elseif ($t >= 0x08 && $t <= 0x2f) {
            $merken = false;
            $wert = $t - 8;
        } elseif ($t == 0x35) {
            $wert = unpack('g', substr($d, $p, 4))[1];
            $p += 4;
        } elseif ($t == 0x36) {
            $wert = unpack('e', substr($d, $p, 8))[1];
            $p += 8;
        } elseif (($t & 0xf0) == 0x30) {
            $n = 2 ** ($t & 0xf);
            $wert = self::le(substr($d, $p, $n));
            $p += $n;
        } elseif ($t >= 0x40 && $t <= 0x60) {
            $n = $t - 0x40;
            $wert = substr($d, $p, $n);
            $p += $n;
        } elseif ($t > 0x60 && $t <= 0x64) {
            $l = $t & 0xf;
            $n = self::le(substr($d, $p, $l));
            $wert = substr($d, $p + $l, $n);
            $p += $l + $n;
        } elseif ($t >= 0x70 && $t <= 0x90) {
            $n = $t - 0x70;
            $wert = new Bytes(substr($d, $p, $n));
            $p += $n;
        } elseif ($t >= 0x91 && $t <= 0x94) {
            $l = 1 << (($t & 0xf) - 1);
            $n = self::le(substr($d, $p, $l));
            $wert = new Bytes(substr($d, $p + $l, $n));
            $p += $l + $n;
        } elseif (($t & 0xf0) == 0xd0 || ($t & 0xe0) == 0xe0) {
            $merken = false;
            $woerterbuch = ($t & 0xf0) != 0xd0;
            $n = $t & 0xf;
            $wert = [];
            for ($i = 0; $n == 0xf ? ord($d[$p]) != 0x03 : $i < $n; $i++) {
                if ($woerterbuch) {
                    $k = self::unpack($d, $p, $objekte);
                    $wert[is_scalar($k) ? $k : json_encode($k)] = self::unpack($d, $p, $objekte);
                } else {
                    $wert[] = self::unpack($d, $p, $objekte);
                }
            }
            if ($n == 0xf) {
                $p++;
            }
        } elseif ($t >= 0xa0 && $t <= 0xc0) {
            $wert = $objekte[$t - 0xa0];
        } elseif ($t >= 0xc1 && $t <= 0xc4) {
            $l = $t - 0xc0;
            $wert = $objekte[self::le(substr($d, $p, $l))];
            $p += $l;
        } else {
            throw new Exception(sprintf('OPACK: unbekannter Typ 0x%02x', $t));
        }
        if ($merken) {
            foreach ($objekte as $o) {
                if ($o instanceof Bytes ? $wert instanceof Bytes && $o->b === $wert->b : $o === $wert) {
                    return $wert;
                }
            }
            $objekte[] = $wert;
        }
        return $wert;
    }

    private static function le(string $b): int
    {
        $w = 0;
        for ($i = strlen($b) - 1; $i >= 0; $i--) {
            $w = ($w << 8) | ord($b[$i]);
        }
        return $w;
    }
}

final class Tlv8
{
    public static function pack(array $t): string
    {
        $r = '';
        foreach ($t as $k => $v) {
            foreach (str_split($v, 255) as $teil) {
                $r .= chr($k) . chr(strlen($teil)) . $teil;
            }
        }
        return $r;
    }

    public static function unpack(string $d): array
    {
        $r = [];
        for ($p = 0; $p + 1 < strlen($d); $p += 2 + ord($d[$p + 1])) {
            $k = ord($d[$p]);
            $r[$k] = ($r[$k] ?? '') . substr($d, $p + 2, ord($d[$p + 1]));
        }
        return $r;
    }
}

final class Crypto
{
    // RFC 5054, 3072 Bit, Generator 5 (HAP)
    private const SRP_N = 'FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD129024E088A67CC74020BBEA63B139B22514A08798E3404DDEF9519B3CD3A431B302B0A6DF25F14374FE1356D6D51C245E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7EDEE386BFB5A899FA5AE9F24117C4B1FE649286651ECE45B3DC2007CB8A163BF0598DA48361C55D39A69163FA8FD24CF5F83655D23DCA3AD961C62F356208552BB9ED529077096966D670C354E4ABC9804F1746C08CA18217C32905E462E36CE3BE39E772C180E86039B2783A2EC07A28FB5C55DF06F4C52C9DE2BCBF6955817183995497CEA956AE515D2261898FA051015728E5A8AAAC42DAD33170D04507A33A85521ABDF1CBA64ECFB850458DBEF0A8AEA71575D060C7DB3970F85A6E1E4C7ABF5AE8CDB0933D71E8C94E04A25619DCEE3D2261AD2EE6BF12FFA06D98A0864D87602733EC86A64521F2B18177B200CBBE117577A615D6C770988C0BAD946E208E24FA074E5AB3143DB5BFCE0FD108E4B82D120A93AD2CAFFFFFFFFFFFFFFFF';
    private const SRP_G = 5;

    public static function hkdf(string $schluessel, string $salz, string $info): string
    {
        return hash_hkdf('sha512', $schluessel, 32, $info, $salz);
    }

    public static function seal(string $schluessel, string $nonce, string $daten, string $aad = ''): string
    {
        return sodium_crypto_aead_chacha20poly1305_ietf_encrypt($daten, $aad, str_pad($nonce, 12, "\0", STR_PAD_LEFT), $schluessel);
    }

    public static function open(string $schluessel, string $nonce, string $daten, string $aad = ''): string
    {
        $r = sodium_crypto_aead_chacha20poly1305_ietf_decrypt($daten, $aad, str_pad($nonce, 12, "\0", STR_PAD_LEFT), $schluessel);
        if ($r === false) {
            throw new Exception('Entschluesselung fehlgeschlagen');
        }
        return $r;
    }

    /**
     * SRP-6a-Client wie srptools (HAP: Benutzer "Pair-Setup", SHA-512,
     * 3072 Bit). Liefert [A, M1, K, erwartetes M2] als Bytes.
     */
    public static function srp(string $seed, string $salz, string $bBytes, string $pin): array
    {
        $N = gmp_init(self::SRP_N, 16);
        $g = gmp_init(self::SRP_G);
        $B = gmp_import($bBytes);
        $k = self::h(self::bytes($N), self::pad($g));
        $a = gmp_import($seed);
        $A = gmp_powm($g, $a, $N);
        $x = self::h($salz, hash('sha512', 'Pair-Setup:' . $pin, true));
        $u = self::h(self::pad($A), self::pad($B));
        $basis = gmp_mod(gmp_sub($B, gmp_mul($k, gmp_powm($g, $x, $N))), $N);
        $K = hash('sha512', self::bytes(gmp_powm($basis, gmp_add($a, gmp_mul($u, $x)), $N)), true);
        $M1 = hash('sha512', self::bytes(gmp_xor(self::h(self::bytes($N)), self::h(self::bytes($g))))
            . self::bytes(self::h('Pair-Setup')) . $salz . self::bytes($A) . self::bytes($B) . $K, true);
        return [self::bytes($A), $M1, $K, hash('sha512', self::bytes($A) . $M1 . $K, true)];
    }

    // SRP-Zahlen wie srptools: Hash als Zahl, Zahlen ohne fuehrende Nullbytes
    private static function bytes(GMP $z): string
    {
        $b = gmp_export($z);
        return $b === '' ? "\0" : $b;
    }

    private static function h(string ...$teile): GMP
    {
        return gmp_import(hash('sha512', implode('', $teile), true));
    }

    private static function pad(GMP $z): string
    {
        return str_pad(self::bytes($z), 384, "\0", STR_PAD_LEFT);
    }
}

/** Eine Verbindung zum Apple TV (Companion, TCP). */
final class Connection
{
    // Rahmentypen
    private const PS_START = 3;
    private const PS_NEXT = 4;
    private const PV_START = 5;
    private const PV_NEXT = 6;
    private const E_OPACK = 8;

    private $s;
    private ?string $aus = null;
    private ?string $ein = null;
    private int $nAus = 0;
    private int $nEin = 0;
    private int $xid;

    /** Optional: fn(string $text) fuer Debug-Ausgaben. */
    public $debug = null;

    public function __construct(string $host, int $port, int $timeout = 3)
    {
        $this->s = @stream_socket_client('tcp://' . $host . ':' . $port, $nr, $text, $timeout);
        if (!$this->s) {
            throw new Exception('Apple TV nicht erreichbar (' . $text . ')');
        }
        stream_set_timeout($this->s, 5);
        $this->xid = random_int(0, 0xffff);
    }

    public function __destruct()
    {
        if ($this->s) {
            fclose($this->s);
        }
    }

    private function senden(int $typ, string $daten): void
    {
        $laenge = strlen($daten) + ($this->aus !== null && $daten !== '' ? 16 : 0);
        $kopf = chr($typ) . substr(pack('N', $laenge), 1);
        if ($this->aus !== null && $daten !== '') {
            $daten = Crypto::seal($this->aus, pack('P', $this->nAus++) . "\0\0\0\0", $daten, $kopf);
        }
        fwrite($this->s, $kopf . $daten);
    }

    private function lesen(int $n): string
    {
        $r = '';
        while (strlen($r) < $n) {
            $teil = fread($this->s, $n - strlen($r));
            if ($teil === false || $teil === '') {
                throw new Exception('Apple TV: keine Antwort (' . (stream_get_meta_data($this->s)['timed_out'] ? 'Zeitueberschreitung' : 'Verbindung geschlossen') . ')');
            }
            $r .= $teil;
        }
        return $r;
    }

    /** Liefert [Rahmentyp, OPACK-Inhalt]. */
    private function empfangen(): array
    {
        $kopf = $this->lesen(4);
        $laenge = unpack('N', "\0" . substr($kopf, 1))[1];
        $daten = $laenge ? $this->lesen($laenge) : '';
        if ($this->ein !== null && $daten !== '') {
            $daten = Crypto::open($this->ein, pack('P', $this->nEin++) . "\0\0\0\0", $daten, $kopf);
        }
        return [ord($kopf[0]), $daten === '' ? null : Opack::unpack($daten)];
    }

    /** Kopplungsschritt: Antwort ist der naechste Rahmen vom Typ PS_Next/PV_Next. */
    private function auth(int $typ, array $nachricht): array
    {
        $this->senden($typ, Opack::pack($nachricht));
        $erwartet = $typ == self::PS_START || $typ == self::PS_NEXT ? self::PS_NEXT : self::PV_NEXT;
        do {
            [$t, $antwort] = $this->empfangen();
            if ($t != $erwartet && $t != 1 && $this->debug) {   // 1 = NoOp, haelt die Verbindung offen
                ($this->debug)("Rahmen $t statt $erwartet erhalten");
            }
        } while ($t != $erwartet);
        $tlv = Tlv8::unpack($antwort['_pd']->b ?? '');
        if (isset($tlv[7])) {
            throw new Exception('Apple TV lehnt ab (Fehler ' . ord($tlv[7]) . ')');
        }
        return $tlv;
    }

    public function command(string $kennung, array $inhalt = []): array
    {
        $x = $this->xid++;
        $this->senden(self::E_OPACK, Opack::pack(['_i' => $kennung, '_t' => 2, '_c' => $inhalt, '_x' => $x]));
        for ($i = 0; $i < 50; $i++) {
            [$t, $antwort] = $this->empfangen();
            if (is_array($antwort) && ($antwort['_t'] ?? 0) == 3 && ($antwort['_x'] ?? -1) == $x) {
                if (isset($antwort['_em'])) {
                    throw new Exception("Apple TV: $kennung fehlgeschlagen ({$antwort['_em']})");
                }
                return is_array($antwort['_c'] ?? null) ? $antwort['_c'] : [];
            }
        }
        throw new Exception("Apple TV: keine Antwort auf $kennung");
    }

    /**
     * HAP Pair-Setup, Teil 1: Das Apple TV zeigt danach eine PIN.
     * Liefert den Zustand fuer pairFinish().
     */
    public function pairStart(): array
    {
        $m2 = $this->auth(self::PS_START, ['_pd' => new Bytes(Tlv8::pack([0 => "\x00", 6 => "\x01"])), '_pwTy' => 1]);
        if (!isset($m2[2], $m2[3])) {
            throw new Exception('Apple TV: unerwartete Antwort auf die Kopplungsanfrage');
        }
        return ['salz' => $m2[2], 'B' => $m2[3]];
    }

    /**
     * HAP Pair-Setup, Teil 2 mit der PIN vom Bildschirm. Ergebnis wie
     * pyatv: ltpk:ltsk:atv_id:client_id (hex).
     */
    public function pairFinish(array $zustand, string $pin, string $name): string
    {
        $seed = random_bytes(32);
        $ed = sodium_crypto_sign_seed_keypair($seed);
        $edGeheim = sodium_crypto_sign_secretkey($ed);
        $edOeffentlich = sodium_crypto_sign_publickey($ed);
        $kennung = sprintf('%s-%s-4%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1),
            dechex(8 + random_int(0, 3)) . substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));

        [$A, $M1, $K, $M2] = Crypto::srp($seed, $zustand['salz'], $zustand['B'], $pin);

        $m4 = $this->auth(self::PS_NEXT, ['_pd' => new Bytes(Tlv8::pack([6 => "\x03", 3 => $A, 4 => $M1])), '_pwTy' => 1]);
        if (!hash_equals($M2, $m4[4] ?? '')) {
            throw new Exception('Apple TV: Bestaetigung passt nicht (falsche PIN?)');
        }

        $geraetX = Crypto::hkdf($K, 'Pair-Setup-Controller-Sign-Salt', 'Pair-Setup-Controller-Sign-Info');
        $sitzung = Crypto::hkdf($K, 'Pair-Setup-Encrypt-Salt', 'Pair-Setup-Encrypt-Info');
        $signatur = sodium_crypto_sign_detached($geraetX . $kennung . $edOeffentlich, $edGeheim);
        $inhalt = Tlv8::pack([1 => $kennung, 3 => $edOeffentlich, 10 => $signatur, 0x11 => Opack::pack(['name' => $name])]);
        $m6 = $this->auth(self::PS_NEXT, ['_pd' => new Bytes(Tlv8::pack([6 => "\x05", 5 => Crypto::seal($sitzung, 'PS-Msg05', $inhalt)])), '_pwTy' => 1]);
        $geraet = Tlv8::unpack(Crypto::open($sitzung, 'PS-Msg06', $m6[5] ?? ''));

        return implode(':', array_map('bin2hex', [$geraet[3], $seed, $geraet[1], $kennung]));
    }

    /** HAP Pair-Verify, danach ist die Verbindung verschluesselt. */
    public function verify(string $kopplung, string $name): void
    {
        $teile = explode(':', $kopplung);
        if (count($teile) != 4) {
            throw new Exception('Kopplung unvollstaendig (neu koppeln)');
        }
        [$ltpk, $ltsk, $atvId, $clientId] = array_map('hex2bin', $teile);
        $geheim = random_bytes(32);
        $oeffentlich = sodium_crypto_scalarmult_base($geheim);

        $m2 = $this->auth(self::PV_START, ['_pd' => new Bytes(Tlv8::pack([6 => "\x01", 3 => $oeffentlich])), '_auTy' => 4]);
        $atvOeffentlich = $m2[3] ?? '';
        if (strlen($atvOeffentlich) != 32) {
            throw new Exception('Apple TV: unerwartete Antwort bei der Anmeldung');
        }
        $gemeinsam = sodium_crypto_scalarmult($geheim, $atvOeffentlich);
        $sitzung = Crypto::hkdf($gemeinsam, 'Pair-Verify-Encrypt-Salt', 'Pair-Verify-Encrypt-Info');
        $tlv = Tlv8::unpack(Crypto::open($sitzung, 'PV-Msg02', $m2[5] ?? ''));
        if (($tlv[1] ?? '') !== $atvId
            || !sodium_crypto_sign_verify_detached($tlv[10] ?? str_repeat("\0", 64), $atvOeffentlich . $atvId . $oeffentlich, $ltpk)) {
            throw new NotConfirmedException('Apple TV: Geraet nicht bestaetigt (neu koppeln)');
        }
        $ed = sodium_crypto_sign_seed_keypair($ltsk);
        $signatur = sodium_crypto_sign_detached($oeffentlich . $clientId . $atvOeffentlich, sodium_crypto_sign_secretkey($ed));
        $inhalt = Tlv8::pack([1 => $clientId, 10 => $signatur]);
        $this->auth(self::PV_NEXT, ['_pd' => new Bytes(Tlv8::pack([6 => "\x03", 5 => Crypto::seal($sitzung, 'PV-Msg03', $inhalt)]))]);

        $this->aus = Crypto::hkdf($gemeinsam, '', 'ClientEncrypt-main');
        $this->ein = Crypto::hkdf($gemeinsam, '', 'ServerEncrypt-main');

        $this->command('_systemInfo', [
            '_bf' => 0, '_cf' => 512, '_clFl' => 128,
            '_i' => substr(bin2hex(hash('sha256', $clientId, true)), 0, 12),
            '_idsID' => new Bytes($clientId),
            '_pubID' => 'FF:53:79:6D:63:6E',
            '_sf' => 256, '_sv' => '170.18', 'model' => 'iPhone10,6', 'name' => $name,
        ]);
        $this->command('_sessionStart', ['_srvT' => 'com.apple.tvremoteservices', '_sid' => random_int(0, 0xffffffff)]);
        try {
            $this->command('TVRCSessionStart', ['ProtocolVersionKey' => '1.2']);
        } catch (Exception $e) {
            // aeltere tvOS kennen das nicht
        }
    }
}

/** Das Apple TV erkennt die Kopplung nicht (mehr) an. */
final class NotConfirmedException extends Exception
{
}

/** Suche per mDNS (Bonjour) nach _companion-link._tcp. */
final class Discovery
{
    /** Modellkennungen (rpMd) in Klartext. */
    public const MODELLE = [
        'AppleTV5,3'  => 'Apple TV HD',
        'AppleTV6,2'  => 'Apple TV 4K',
        'AppleTV11,1' => 'Apple TV 4K (2. Generation)',
        'AppleTV14,1' => 'Apple TV 4K (3. Generation)',
    ];

    /**
     * Liefert alle Apple TVs:
     * [['name' => ..., 'host' => ..., 'port' => ..., 'model' => ...], ...]
     */
    public static function search(int $sekunden = 2): array
    {
        $frage = pack('n6', 0, 0, 1, 0, 0, 0) . "\x0f_companion-link\x04_tcp\x05local\0" . pack('nn', 12, 0x8001);
        $s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($s === false) {
            throw new Exception('mDNS: Socket nicht verfuegbar');
        }
        socket_set_option($s, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $sekunden, 'usec' => 0]);
        socket_set_option($s, IPPROTO_IP, IP_MULTICAST_TTL, 255);
        socket_sendto($s, $frage, strlen($frage), 0, '224.0.0.251', 5353);
        $srv = $txt = $a = [];
        for ($i = 0; $i < 20 && @socket_recvfrom($s, $d, 9000, 0, $von, $port); $i++) {
            if (strlen($d) < 12) {
                continue;
            }
            $p = 12;
            $kopf = unpack('n6', substr($d, 0, 12));
            for ($q = 0; $q < $kopf[3]; $q++) {
                self::name($d, $p);
                $p += 4;
            }
            $anzahl = $kopf[4] + $kopf[5] + $kopf[6];
            for ($r = 0; $r < $anzahl && $p + 10 <= strlen($d); $r++) {
                $name = self::name($d, $p);
                $f = unpack('ntyp/nklasse/Nttl/nlaenge', substr($d, $p, 10));
                $p += 10;
                $inhalt = $p;
                if ($f['typ'] == 33) {          // SRV
                    $port = unpack('n', substr($d, $p + 4, 2))[1];
                    $q2 = $p + 6;
                    $srv[$name] = [$port, self::name($d, $q2)];
                } elseif ($f['typ'] == 16) {    // TXT
                    for ($q2 = $p; $q2 < $p + $f['laenge']; $q2 += 1 + ord($d[$q2])) {
                        $eintrag = explode('=', substr($d, $q2 + 1, ord($d[$q2])), 2);
                        $txt[$name][$eintrag[0]] = $eintrag[1] ?? '';
                    }
                } elseif ($f['typ'] == 1 && $f['laenge'] == 4) {
                    $a[$name] = inet_ntop(substr($d, $p, 4));
                }
                $p = $inhalt + $f['laenge'];
            }
        }
        socket_close($s);
        $geraete = [];
        foreach ($srv as $name => [$port, $host]) {
            $modell = $txt[$name]['rpMd'] ?? '';
            if (str_starts_with($modell, 'AppleTV') && isset($a[$host])) {
                $geraete[] = [
                    'name'  => explode('._companion-link', $name)[0],
                    'host'  => $a[$host],
                    'port'  => $port,
                    'model' => $modell,
                ];
            }
        }
        return $geraete;
    }

    public static function modelName(string $kennung): string
    {
        return self::MODELLE[$kennung] ?? $kennung;
    }

    private static function name(string $d, int &$p): string
    {
        $teile = [];
        $sprung = -1;
        for ($i = 0; $i < 64; $i++) {
            $n = ord($d[$p] ?? "\0");
            if ($n == 0) {
                $p++;
                break;
            }
            if (($n & 0xc0) == 0xc0) {
                $ziel = (($n & 0x3f) << 8) | ord($d[$p + 1] ?? "\0");
                if ($sprung < 0) {
                    $sprung = $p + 2;
                }
                $p = $ziel;
                continue;
            }
            $teile[] = substr($d, $p + 1, $n);
            $p += 1 + $n;
        }
        if ($sprung >= 0) {
            $p = $sprung;
        }
        return implode('.', $teile);
    }
}
