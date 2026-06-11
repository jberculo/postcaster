<?php

declare(strict_types=1);

use Justbee\PostCaster\Services\PostRenderer;
use Justbee\PostCaster\Templates\TemplateFitter;
use Justbee\PostCaster\Templates\TemplateParser;
use Justbee\PostCaster\Templates\TemplateRenderer;

final class PostRendererTest extends WP_UnitTestCase
{
    private PostRenderer $renderer;

    public function set_up(): void
    {
        parent::set_up();
        $this->renderer = new PostRenderer(new TemplateRenderer(new TemplateParser(), new TemplateFitter()));
    }

    /**
     * @dataProvider decodeProvider
     */
    public function test_decode_resolves_input_correctly(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->renderer->decode($input));
    }

    /** @return array<string, array{0:string,1:string}> */
    public function decodeProvider(): array
    {
        return [
            'basic entity' => ['Hello &amp; welcome', 'Hello & welcome'],
            'named html5 entities' => ['caf&eacute; &mdash; r&eacute;sum&eacute;', 'café — résumé'],
            'double-encoded across passes' => ['&amp;amp;', '&'],
            'plain text passes through' => ['no entities here', 'no entities here'],
            'empty input' => ['', ''],
        ];
    }

    public function test_decode_is_idempotent_on_repeated_calls(): void
    {
        $first = $this->renderer->decode('&quot;quoted&quot;');
        $second = $this->renderer->decode('&quot;quoted&quot;');
        $this->assertSame('"quoted"', $first);
        $this->assertSame($first, $second);
    }
}
