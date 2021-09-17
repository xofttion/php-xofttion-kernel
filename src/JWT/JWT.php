<?php

namespace Xofttion\Kernel\JWT;

use DomainException;
use InvalidArgumentException;
use UnexpectedValueException;
use DateTime;
use ArrayAccess;

class JWT
{

    // Atributos de la clase JWT

    public static $timestamp = null;

    public static $leeway = 0;

    public static $supported_algs = [
        'ES256' => array('openssl', 'SHA256'),
        'HS256' => array('hash_hmac', 'SHA256'),
        'HS384' => array('hash_hmac', 'SHA384'),
        'HS512' => array('hash_hmac', 'SHA512'),
        'RS256' => array('openssl', 'SHA256'),
        'RS384' => array('openssl', 'SHA384'),
        'RS512' => array('openssl', 'SHA512')
    ];

    // Constantes de la clase JWT

    const ASN1_INTEGER = 0x02;

    const ASN1_SEQUENCE = 0x10;

    const ASN1_BIT_STRING = 0x03;

    // Métodos estáticos de la clase JWT

    public static function decode($jwt, $key, array $allowed_algs = array())
    {
        $timestamp = is_null(static::$timestamp) ? time() : static::$timestamp;

        if (empty($key)) {
            throw new InvalidArgumentException("Key may not be empty");
        }

        $tks = explode('.', $jwt);

        if (count($tks) != 3) {
            throw new UnexpectedValueException("Wrong number of segments");
        }

        list($headb64, $bodyb64, $cryptob64) = $tks;

        if (null === ($header = static::jsonDecode(static::urlsafeB64Decode($headb64)))) {
            throw new UnexpectedValueException("Invalid header encoding");
        }

        if (null === $payload = static::jsonDecode(static::urlsafeB64Decode($bodyb64))) {
            throw new UnexpectedValueException("Invalid claims encoding");
        }

        if (false === ($sig = static::urlsafeB64Decode($cryptob64))) {
            throw new UnexpectedValueException("Invalid signature encoding");
        }

        if (empty($header->alg)) {
            throw new UnexpectedValueException("Empty algorithm");
        }

        if (empty(static::$supported_algs[$header->alg])) {
            throw new UnexpectedValueException("Algorithm not supported");
        }

        if (!in_array($header->alg, $allowed_algs)) {
            throw new UnexpectedValueException("Algorithm not allowed");
        }

        if ($header->alg === "ES256") {
            $sig = self::signatureToDER($sig); // OpenSSL expects an ASN.1 DER sequence for ES256 signatures
        }

        if (is_array($key) || $key instanceof ArrayAccess) {
            if (isset($header->kid)) {
                if (!isset($key[$header->kid])) {
                    throw new UnexpectedValueException("'kid' invalid, unable to lookup correct key");
                }

                $key = $key[$header->kid];
            }
            else {
                throw new UnexpectedValueException("'kid' empty, unable to lookup correct key");
            }
        }

        if (!static::verify("$headb64.$bodyb64", $sig, $key, $header->alg)) {
            throw new SignatureInvalidException("Signature verification failed");
        } // Check the signature

        if (isset($payload->nbf) && $payload->nbf > ($timestamp + static::$leeway)) {
            throw new BeforeValidException("Cannot handle token prior to " . date(DateTime::ISO8601, $payload->nbf));
        }

        if (isset($payload->iat) && $payload->iat > ($timestamp + static::$leeway)) {
            throw new BeforeValidException("Cannot handle token prior to " . date(DateTime::ISO8601, $payload->iat));
        }

        if (isset($payload->exp) && ($timestamp - static::$leeway) >= $payload->exp) {
            throw new ExpiredException('Expired token');
        }

        return $payload; // Retornando Payload del Token generado
    }

    public static function encode($payload, $key, $alg = "HS256", $keyId = null, $head = null)
    {
        $header = array("typ" => "'JWT", "alg" => $alg);

        if ($keyId !== null) {
            $header['kid'] = $keyId;
        }

        if (isset($head) && is_array($head)) {
            $header = array_merge($head, $header);
        }

        $segments = array();
        $segments[] = static::urlsafeB64Encode(static::jsonEncode($header));
        $segments[] = static::urlsafeB64Encode(static::jsonEncode($payload));

        $signing_input = implode('.', $segments);

        $signature = static::sign($signing_input, $key, $alg);
        $segments[] = static::urlsafeB64Encode($signature);

        return implode('.', $segments);
    }

    public static function sign($msg, $key, $alg = "HS256")
    {
        if (empty(static::$supported_algs[$alg])) {
            throw new DomainException('Algorithm not supported');
        }

        list($function, $algorithm) = static::$supported_algs[$alg];

        switch ($function) {
            case ("hash_hmac"):
                return hash_hmac($algorithm, $msg, $key, true);

            case ("openssl"):
                $signature = "";
                $success = openssl_sign($msg, $signature, $key, $algorithm);

                if (!$success) {
                    throw new DomainException("OpenSSL unable to sign data");
                }
                else {
                    if ($alg === "ES256") {
                        $signature = self::signatureFromDER($signature, 256);
                    }

                    return $signature;
                }
        }
    }

    private static function verify($msg, $signature, $key, $alg)
    {
        if (empty(static::$supported_algs[$alg])) {
            throw new DomainException("Algorithm not supported");
        }

        list($function, $algorithm) = static::$supported_algs[$alg];

        switch ($function) {
            case ("openssl"):
                $success = openssl_verify($msg, $signature, $key, $algorithm);

                if ($success === 1) {
                    return true;
                }
                elseif ($success === 0) {
                    return false;
                }

                throw new DomainException("OpenSSL error: " . openssl_error_string());

            case ("hash_hmac"):

            default:
                $hash = hash_hmac($algorithm, $msg, $key, true);
                if (function_exists('hash_equals')) {
                    return hash_equals($signature, $hash);
                }

                $size = min(static::safeStrlen($signature), static::safeStrlen($hash));

                $status = 0;

                for ($i = 0; $i < $size; $i++) {
                    $status |= (ord($signature[$i]) ^ ord($hash[$i]));
                }

                $status |= (static::safeStrlen($signature) ^ static::safeStrlen($hash));

                return ($status === 0);
        }
    }

    public static function jsonDecode($input)
    {
        if (version_compare(PHP_VERSION, '5.4.0', '>=') && !(defined('JSON_C_VERSION') && PHP_INT_SIZE > 4)) {
            $obj = json_decode($input, false, 512, JSON_BIGINT_AS_STRING);
        }
        else {
            $max_int_length = strlen((string)PHP_INT_MAX) - 1;
            $json_without_bigints = preg_replace('/:\s*(-?\d{' . $max_int_length . ',})/', ': "$1"', $input);
            $obj = json_decode($json_without_bigints);
        }

        if ($errno = json_last_error()) {
            static::handleJsonError($errno);
        }
        elseif ($obj === null && $input !== 'null') {
            throw new DomainException('Null result with non-null input');
        }

        return $obj;
    }

    public static function jsonEncode($input)
    {
        $json = json_encode($input);

        if ($errno = json_last_error()) {
            static::handleJsonError($errno);
        }
        elseif ($json === 'null' && $input !== null) {
            throw new DomainException('Null result with non-null input');
        }

        return $json;
    }

    public static function urlsafeB64Decode($input)
    {
        $remainder = strlen($input) % 4;

        if ($remainder) {
            $padlen = 4 - $remainder;
            $input .= str_repeat('=', $padlen);
        }

        return base64_decode(strtr($input, '-_', '+/'));
    }

    public static function urlsafeB64Encode($input)
    {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }

    private static function handleJsonError($errno)
    {
        $messages = array(
            JSON_ERROR_DEPTH => 'Maximum stack depth exceeded',
            JSON_ERROR_STATE_MISMATCH => 'Invalid or malformed JSON',
            JSON_ERROR_CTRL_CHAR => 'Unexpected control character found',
            JSON_ERROR_SYNTAX => 'Syntax error, malformed JSON',
            JSON_ERROR_UTF8 => 'Malformed UTF-8 characters' //PHP >= 5.3.3
        );

        throw new DomainException(isset($messages[$errno]) ? $messages[$errno] : 'Unknown JSON error: ' . $errno);
    }

    private static function safeStrlen($str)
    {
        return (function_exists("mb_strlen")) ? mb_strlen($str, "8bit") : strlen($str);
    }

    private static function signatureToDER($sig)
    {
        list($r, $s) = str_split($sig, (int)(strlen($sig) / 2));

        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        if (ord($r[0]) > 0x7f) {
            $r = "\x00" . $r;
        }

        if (ord($s[0]) > 0x7f) {
            $s = "\x00" . $s;
        }

        return self::encodeDER(
            self::ASN1_SEQUENCE,
            self::encodeDER(self::ASN1_INTEGER, $r) .
            self::encodeDER(self::ASN1_INTEGER, $s)
        );
    }

    private static function encodeDER($type, $value)
    {
        $tag_header = 0;

        if ($type === self::ASN1_SEQUENCE) {
            $tag_header |= 0x20;
        }

        $der = chr($tag_header | $type); // Type

        $der .= chr(strlen($value)); // Length

        return $der . $value;
    }

    private static function signatureFromDER($der, $keySize)
    {
        list($offset, $_) = self::readDER($der);
        list($offset, $r) = self::readDER($der, $offset);
        list($offset, $s) = self::readDER($der, $offset);

        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        $r = str_pad($r, $keySize / 8, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, $keySize / 8, "\x00", STR_PAD_LEFT);

        return "$r$s";
    }

    private static function readDER($der, $offset = 0)
    {
        $pos = $offset;
        $size = strlen($der);
        $constructed = (ord($der[$pos]) >> 5) & 0x01;
        $type = ord($der[$pos++]) & 0x1f;

        $len = ord($der[$pos++]); // Length

        if ($len & 0x80) {
            $n = $len & 0x1f;
            $len = 0;
            while ($n-- && $pos < $size) {
                $len = ($len << 8) | ord($der[$pos++]);
            }
        }

        // Value
        if ($type == self::ASN1_BIT_STRING) {
            $pos++; // Skip the first contents octet (padding indicator)
            $data = substr($der, $pos, $len - 1);

            if (!$ignore_bit_strings) {
                $pos += $len - 1;
            }
        }
        elseif (!$constructed) {
            $data = substr($der, $pos, $len);
            $pos += $len;
        }
        else {
            $data = null;
        }

        return array($pos, $data);
    }
}
