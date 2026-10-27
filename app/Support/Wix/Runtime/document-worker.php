<?php

/** Static trusted entry point. No application bootstrap, caller path, shell or code payload. */
use App\Support\Wix\Http\NativeExchange;
use App\Support\Wix\Runtime\ChildTaskProtocol;
use App\Support\Wix\Runtime\JsonLexemes;

require __DIR__.'/ChildTaskProtocol.php';

$deadline = (int) ($argv[2] ?? 0);
if (! posix_setrlimit(POSIX_RLIMIT_AS, 268435456, 268435456)) {
    exit(70);
}
$check = static function () use ($deadline): void {
    if (hrtime(true) >= $deadline) {
        exit(71);
    }
};
$read = static function () use ($check): string {
    $exact = static function (int $size) use ($check): string {
        $data = '';
        while (strlen($data) < $size) {
            $check();
            $piece = fread(STDIN, $size - strlen($data));
            if ($piece === false || $piece === '') {
                exit(72);
            }
            $data .= $piece;
        }

        return $data;
    };
    $size = unpack('N', $exact(4))[1];
    if ($size < 1 || $size > ChildTaskProtocol::FrameBytes) {
        exit(73);
    }

    return $exact($size);
};
$emit = static function (string $frame) use ($check): void {
    $wire = ChildTaskProtocol::frame($frame);
    while ($wire !== '') {
        $check();
        $size = fwrite(STDOUT, substr($wire, 0, 4096));
        if ($size === false || $size === 0) {
            exit(74);
        }
        $wire = substr($wire, $size);
    }
};
$options = json_decode($read(), true, 8, JSON_THROW_ON_ERROR);
$inputBytes = $options['input_bytes'] ?? -1;
if (! is_int($inputBytes) || $inputBytes < 0 || $inputBytes > 2097152) {
    exit(75);
}
$body = '';
while (strlen($body) < $inputBytes) {
    $chunk = $read();
    if (strlen($chunk) > $inputBytes - strlen($body)) {
        exit(76);
    }
    $body .= $chunk;
}
$units = 0;
$maxUnits = $options['max_nodes'] ?? 0;
$node = static function (array $value) use ($check, $emit, &$units, $maxUnits): void {
    $check();
    if (++$units > $maxUnits) {
        exit(77);
    }
    $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $size = strlen($json);
    for ($offset = 0; $offset < $size; $offset += ChildTaskProtocol::FrameBytes - 1) {
        $last = $size - $offset <= ChildTaskProtocol::FrameBytes - 1;
        $emit(($last ? 'J' : 'j').substr($json, $offset, ChildTaskProtocol::FrameBytes - 1));
    }
};
if ($argv[1] === 'lexemes') {
    require __DIR__.'/JsonLexemes.php';
    $emit('B');
    json_decode($body, false, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    $emit('C');
    (new JsonLexemes($body, $node, $check))->walk();
} elseif ($argv[1] === 'json') {
    $emit('B');
    $value = json_decode($body, false, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    $emit('C');
    $walk = static function (mixed $value, array $path) use (&$walk, $node, $check): void {
        $check();
        $type = is_object($value) ? 'object' : (is_array($value) ? 'array' : 'scalar');
        $node(['path' => $path, 'type' => $type, 'empty' => $type !== 'scalar' && count((array) $value) === 0, 'value' => $type === 'scalar' ? $value : null]);
        if ($type !== 'scalar') {
            foreach ($value as $key => $child) {
                if ($type === 'object' && ctype_digit((string) $key)) {
                    exit(82);
                }
                $walk($child, [...$path, $key]);
            }
        }
    };
    $walk($value, []);
} elseif ($argv[1] === 'html') {
    if (preg_match('/<!\s*(?:ENTITY|DOCTYPE[^>]*\[)/i', $body)) {
        exit(78);
    }
    $previous = libxml_use_internal_errors(true);
    try {
        $dom = new DOMDocument;
        $emit('B');
        if (! $dom->loadHTML($body, LIBXML_NONET | LIBXML_COMPACT)) {
            exit(79);
        }
        $emit('C');
        $walk = static function (DOMNode $element, int $depth) use (&$walk, $node, $check): void {
            $check();
            if ($depth > 64) {
                exit(80);
            }
            if ($element instanceof DOMElement) {
                $attributes = [];
                foreach ($element->attributes as $attribute) {
                    $attributes[] = [$attribute->name, $attribute->value];
                }
                $text = '';
                foreach ($element->childNodes as $child) {
                    if ($child instanceof DOMText || $child instanceof DOMCdataSection) {
                        $text .= $child->nodeValue;
                    }
                }
                if ($element->tagName !== 'script' || in_array($element->getAttribute('type'), ['application/ld+json', 'text/json'], true)) {
                    $content = in_array($element->tagName, ['td', 'th', 'p', 'li', 'a', 'span'], true) ? $element->textContent : null;
                    $node(['locator' => $element->getNodePath(), 'name' => $element->tagName, 'text' => $text, 'content' => $content, 'attributes' => $attributes]);
                }
            }
            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $walk($child, $depth + 1);
                }
            }
        };
        $walk($dom->documentElement, 0);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
} elseif ($argv[1] === 'receive') {
    require __DIR__.'/../Http/BoundedBodySink.php';
    require __DIR__.'/../Http/NativeExchange.php';
    $report = (new NativeExchange)->send($options, static function (string $chunk) use ($emit): void {
        for ($offset = 0; $offset < strlen($chunk); $offset += ChildTaskProtocol::FrameBytes - 1) {
            $emit('D'.substr($chunk, $offset, ChildTaskProtocol::FrameBytes - 1));
        }
    });
    $emit('R'.json_encode($report, JSON_THROW_ON_ERROR));
} else {
    exit(81);
}
$emit('E');
