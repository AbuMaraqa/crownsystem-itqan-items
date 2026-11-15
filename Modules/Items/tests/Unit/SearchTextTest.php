<?php

namespace Modules\Items\tests\Unit;

use Modules\Items\Support\Search\KeyboardLayout;
use Modules\Items\Support\Search\SearchHighlighter;
use Modules\Items\Support\Search\SearchQuery;
use Modules\Items\Support\Search\SearchScorer;
use Modules\Items\Support\Search\TextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * تحليل نص البحث وترتيب النتائج وتمييز المطابقة، دون قاعدة بيانات.
 */
class SearchTextTest extends TestCase
{
    public function test_arabic_spelling_variants_normalize_to_one_form(): void
    {
        $this->assertSame('الوميوم ابيض', TextNormalizer::normalize('ألومِيـــوم أبيض'));
        $this->assertSame('درفه منزلقه', TextNormalizer::normalize('دَرفة منزلقة'));
        $this->assertSame('مستشفي اسلاميه', TextNormalizer::normalize('مستشفى إسلامية'));
        $this->assertSame('مسوول هييه', TextNormalizer::normalize('مسؤول هيئة'));
        $this->assertSame('00301 k4593', TextNormalizer::normalize('٠٠٣٠١ — K4593'));
        $this->assertSame('d 2000', TextNormalizer::normalize('D-2000'));
        $this->assertSame('d2000', TextNormalizer::compact('D-2000'));
    }

    public function test_index_words_add_the_form_without_the_article_and_code_parts(): void
    {
        $words = TextNormalizer::indexWords('الباب السحاب K4593');

        $this->assertContains('الباب', $words);
        $this->assertContains('باب', $words);
        $this->assertContains('سحاب', $words);
        $this->assertContains('k4593', $words);
        $this->assertContains('4593', $words);
        // "ال" لا تُنزع من كلمة قصيرة (الا ← ا).
        $this->assertNotContains('ا', TextNormalizer::indexWords('الا'));
    }

    public function test_offsets_map_the_normalized_text_back_to_the_original(): void
    {
        [$normalized, $offsets] = TextNormalizer::normalizeWithOffsets('بَاب');

        // الفتحة حُذفت، فالحرف الثاني في النص الموحَّد هو الثالث في الاصل.
        $this->assertSame('باب', $normalized);
        $this->assertSame([0, 2, 3], $offsets);
    }

    public function test_keyboard_layout_is_corrected_in_both_directions(): void
    {
        $this->assertSame('المنيوم', KeyboardLayout::swap('hglkd,l'));
        $this->assertSame('باب', KeyboardLayout::swap('FHF'));
        $this->assertSame('door', KeyboardLayout::swap('يخخق'));
        // الخليط والارقام وحدها لا تُحوَّل.
        $this->assertNull(KeyboardLayout::swap('باب door'));
        $this->assertNull(KeyboardLayout::swap('12345'));
    }

    public function test_the_query_builds_a_fulltext_expression_and_like_patterns(): void
    {
        $query = SearchQuery::from('  الباب   3   سحاب ');

        $this->assertSame(['الباب', '3', 'سحاب'], $query->terms);
        $this->assertSame(['3'], $query->shortTerms(3));
        $this->assertSame('+(الباب* باب*) +سحاب*', $query->booleanFullText(3));
        $this->assertSame('(الباب* باب*) سحاب*', $query->booleanFullText(3, requireAll: false));
        $this->assertSame([['% الباب%', '% باب%'], ['% 3%'], ['% سحاب%']], $query->likePatterns());
        $this->assertSame([['%3%']], $query->likePatterns(anywhere: true, terms: ['3']));
        $this->assertTrue(SearchQuery::from(" \t ")->isEmpty());
        $this->assertSame(SearchQuery::MAX_TERMS, count(SearchQuery::from('a b c d e f g h i j k')->terms));
    }

    public function test_exact_code_beats_a_name_match_and_name_start_beats_a_middle_match(): void
    {
        $query = SearchQuery::from('1200');

        $exactCode = SearchScorer::score($query, ['ثابت 3 مجاري'], '1200', null);
        $inName = SearchScorer::score($query, ['المنيوم سبير 1200'], '00301', null);

        $this->assertGreaterThan($inName, $exactCode);

        $query = SearchQuery::from('باب');

        $this->assertGreaterThan(
            SearchScorer::score($query, ['شباك باب جرار'], 'A', null),
            SearchScorer::score($query, ['باب جرار'], 'B', null),
        );
    }

    public function test_more_matched_words_rank_higher_and_inactive_items_drop(): void
    {
        $query = SearchQuery::from('المنيوم سبير ابيض');

        $all = SearchScorer::score($query, ['ألمنيوم سبير أبيض'], 'A', null);
        $some = SearchScorer::score($query, ['ألمنيوم أسود'], 'B', null);
        $inactive = SearchScorer::score($query, ['ألمنيوم سبير أبيض'], 'C', null, active: false);

        $this->assertGreaterThan($some, $all);
        $this->assertGreaterThan($inactive, $all);
    }

    public function test_matches_are_highlighted_on_the_original_text(): void
    {
        $html = (string) SearchHighlighter::highlight('ألمنيوم <أبيض>', SearchQuery::from('المنيوم ابيض'));

        $this->assertStringContainsString('>ألمنيوم</mark>', $html);
        $this->assertStringContainsString('&lt;<mark', $html);
        $this->assertStringContainsString('>أبيض</mark>&gt;', $html);

        // الكلمة القصيرة تُميَّز في اول الكلمة فقط.
        $html = (string) SearchHighlighter::highlight('باب 3 درفات 300', SearchQuery::from('3'));

        $this->assertSame(2, substr_count($html, '<mark'));
    }
}
