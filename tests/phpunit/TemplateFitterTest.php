<?php

declare(strict_types=1);

use Justbee\PostCaster\Templates\TemplateFitter;
use Justbee\PostCaster\Templates\TemplateSegment;

final class TemplateFitterTest extends WP_UnitTestCase
{
    private TemplateFitter $fitter;

    public function set_up(): void
    {
        parent::set_up();
        $this->fitter = new TemplateFitter();
    }

    private function literal(string $text): TemplateSegment
    {
        return new TemplateSegment($text, TemplateSegment::POLICY_NEUTRAL, TemplateSegment::STRATEGY_NONE, 3, 'literal');
    }

    private function ellipsis(string $text, int $priority = 1): TemplateSegment
    {
        return new TemplateSegment($text, TemplateSegment::POLICY_PREFER, TemplateSegment::STRATEGY_ELLIPSIS, $priority, 'excerpt');
    }

    private function url(string $text): TemplateSegment
    {
        return new TemplateSegment($text, TemplateSegment::POLICY_FORBIDDEN, TemplateSegment::STRATEGY_NONE, 99, 'url');
    }

    private function tags(string $text, int $priority = 1): TemplateSegment
    {
        return new TemplateSegment($text, TemplateSegment::POLICY_PREFER, TemplateSegment::STRATEGY_DROP_ITEMS, $priority, 'tags');
    }

    private function join(array $segments): string
    {
        return implode('', array_map(static fn(TemplateSegment $s): string => $s->getText(), $segments));
    }

    public function test_short_enough_segments_are_returned_unchanged(): void
    {
        $segments = [$this->literal('hello'), $this->ellipsis('world')];
        $result = $this->fitter->fit($segments, 100);

        $this->assertSame('helloworld', $this->join($result));
    }

    public function test_zero_or_negative_limit_is_a_no_op(): void
    {
        $segments = [$this->literal('a very long literal string')];
        $this->assertSame($segments, $this->fitter->fit($segments, 0));
        $this->assertSame($segments, $this->fitter->fit($segments, -10));
    }

    public function test_forbidden_segment_is_never_shrunk(): void
    {
        $segments = [
            $this->ellipsis('intro text'),
            $this->url('https://example.test/very/long/url/that/must/remain/intact'),
        ];

        $result = $this->fitter->fit($segments, 30);

        $url = $result[1]->getText();
        $this->assertSame('https://example.test/very/long/url/that/must/remain/intact', $url, 'URL segment must never be touched.');
    }

    public function test_ellipsis_shortens_preferred_segment_first(): void
    {
        $segments = [
            $this->ellipsis('this is a fairly long excerpt with several words', 1),
            $this->literal(' '),
            $this->url('https://example.test/a'),
        ];

        $result = $this->fitter->fit($segments, 40);

        $this->assertLessThanOrEqual(40, $this->fitter->segmentsLength($result));
        $this->assertStringEndsWith('...', $result[0]->getText(), 'Ellipsis strategy should append an ellipsis.');
        $this->assertSame(' ', $result[1]->getText(), 'Neutral literal must be preserved while a higher-priority shrinkable remains.');
        $this->assertSame('https://example.test/a', $result[2]->getText());
    }

    public function test_ellipsis_prefers_word_boundary(): void
    {
        $segments = [$this->ellipsis('alpha beta gamma delta epsilon', 1)];

        $result = $this->fitter->fit($segments, 15);
        $text = $result[0]->getText();

        $this->assertLessThanOrEqual(15, mb_strlen($text, 'UTF-8'));
        $this->assertStringEndsWith('...', $text);
        $this->assertStringNotContainsString('gamm...', $text, 'Should cut at word boundary, not mid-word, when possible.');
    }

    public function test_ellipsis_handles_very_small_target_length(): void
    {
        $segments = [$this->ellipsis('much longer than three characters', 1)];

        $result = $this->fitter->fit($segments, 3);
        $text = $result[0]->getText();

        $this->assertLessThanOrEqual(3, mb_strlen($text, 'UTF-8'));
    }

    public function test_drop_items_removes_tags_from_the_end(): void
    {
        $segments = [
            $this->literal('body '),
            $this->tags('#alpha #beta #gamma #delta #epsilon', 1),
        ];

        $result = $this->fitter->fit($segments, 20);
        $tagText = $result[1]->getText();

        $this->assertLessThanOrEqual(20, $this->fitter->segmentsLength($result));
        $this->assertStringStartsWith('#alpha', $tagText, 'Earlier tags must be preserved.');
        $this->assertStringNotContainsString('#epsilon', $tagText, 'Later tags should be dropped first.');
    }

    public function test_drop_items_keeps_at_least_one_tag(): void
    {
        $segments = [
            $this->literal('body '),
            $this->tags('#alpha #beta', 1),
        ];

        $result = $this->fitter->fit($segments, 6);

        $tagText = $result[1]->getText();
        $this->assertNotSame('', $tagText, 'At least one tag must remain even under extreme pressure.');
    }

    public function test_priority_1_shrunk_before_priority_2(): void
    {
        $segments = [
            $this->ellipsis('high-priority excerpt text that is long', 1),
            $this->literal(' / '),
            new TemplateSegment(
                'low-priority title that is also long',
                TemplateSegment::POLICY_NEUTRAL,
                TemplateSegment::STRATEGY_ELLIPSIS,
                2,
                'title'
            ),
        ];

        $result = $this->fitter->fit($segments, 60);

        // Priority 1 should take the hit first: it's measurably shorter than the priority 2 title.
        $this->assertLessThan(mb_strlen($segments[0]->getText(), 'UTF-8'), $result[0]->length());
        $this->assertSame($segments[2]->getText(), $result[2]->getText(), 'Priority 2 should still be intact when priority 1 shrinkage is enough.');
    }

    public function test_segments_length_aggregates_multi_byte_correctly(): void
    {
        $segments = [$this->literal('café'), $this->literal('—')];

        $this->assertSame(5, $this->fitter->segmentsLength($segments), 'mb_strlen should count characters, not bytes.');
    }
}
