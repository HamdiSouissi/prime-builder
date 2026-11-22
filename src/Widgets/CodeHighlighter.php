<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

/** A small, bounded highlighter: source is always text, never executable markup. */
final class CodeHighlighter
{
    /** @var array<string, string> */
    private const PATTERNS = [
        'html' => <<<'REGEX'
~(?<comment><!--[\s\S]*?(?:-->|\z))|(?<tag></?[A-Za-z][^<>]*>|<![A-Za-z][^<>]*>)~
REGEX,
        'css' => <<<'REGEX'
~(?<comment>/\*[\s\S]*?(?:\*/|\z))|(?<string>(?<!\\)(?:"(?:\\[\s\S]|[^"\\])*"|'(?:\\[\s\S]|[^'\\])*'))|(?<number>#[0-9a-fA-F]{3,8}\b|\b\d+(?:\.\d+)?(?:px|em|rem|vh|vw|%)?)|(?<keyword>\b(?:important|inherit|initial|unset|auto|none|transparent)\b)~
REGEX,
        'javascript' => <<<'REGEX'
~(?<comment>/\*[\s\S]*?(?:\*/|\z)|//[^\r\n]*)|(?<string>(?<!\\)(?:"(?:\\[\s\S]|[^"\\])*"|'(?:\\[\s\S]|[^'\\])*'|`(?:\\[\s\S]|[^`\\])*`))|(?<number>\b\d+(?:\.\d+)?\b)|(?<keyword>\b(?:async|await|break|case|catch|class|const|continue|default|delete|do|else|export|extends|false|finally|for|function|if|import|in|instanceof|let|new|null|return|static|super|switch|this|throw|true|try|typeof|undefined|var|while|yield)\b)~
REGEX,
        'json' => <<<'REGEX'
~(?<string>(?<!\\)"(?:\\[\s\S]|[^"\\])*")|(?<number>-?\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)|(?<keyword>\b(?:true|false|null)\b)~
REGEX,
        'php' => <<<'REGEX'
~(?<comment>/\*[\s\S]*?(?:\*/|\z)|//[^\r\n]*|\#[^\r\n]*)|(?<string>(?<!\\)(?:"(?:\\[\s\S]|[^"\\])*"|'(?:\\[\s\S]|[^'\\])*'))|(?<tag><\?php|\?>)|(?<variable>\$[A-Za-z_][A-Za-z0-9_]*)|(?<number>\b\d+(?:\.\d+)?\b)|(?<keyword>\b(?:abstract|array|as|break|case|catch|class|const|continue|declare|default|do|echo|else|elseif|extends|false|final|finally|for|foreach|function|if|implements|interface|namespace|new|null|private|protected|public|readonly|return|static|throw|trait|true|try|use|while)\b)~i
REGEX,
    ];

    public static function render(string $source, string $language): string
    {
        $pattern = self::PATTERNS[$language] ?? null;
        if ($pattern === null) {
            return Renderer::escape($source);
        }
        $html = '';
        $offset = 0;
        $length = strlen($source);
        // Bound markup expansion for code containing thousands of tiny tokens.
        for ($count = 0; $count < 1000 && $offset < $length; $count++) {
            $found = preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE, $offset);
            if ($found !== 1) {
                break;
            }
            $token = $matches[0][0];
            $start = $matches[0][1];
            $kind = '';
            foreach (['comment', 'string', 'tag', 'variable', 'number', 'keyword'] as $name) {
                if (isset($matches[$name]) && $matches[$name][1] >= 0) {
                    $kind = $name;
                    break;
                }
            }
            if ($kind === '' || $start < $offset) {
                break;
            }
            $html .= Renderer::escape(substr($source, $offset, $start - $offset));
            $html .= '<span class="pb-code-token--' . $kind . '">' . Renderer::escape($token) . '</span>';
            $offset = $start + strlen($token);
        }
        return $html . Renderer::escape(substr($source, $offset));
    }
}
