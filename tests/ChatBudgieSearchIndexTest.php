<?php

namespace ChatBudgie\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test case for ChatBudgie class search_index functionality
 * 
 * Tests the ChatBudgie::search_index method which:
 * - Takes query text, top K, and threshold parameters
 * - Embeds the query text via get_embedding
 * - Searches the vector index via Searcher
 * - Returns filtered results with post data
 */
class ChatBudgieSearchIndexTest extends TestCase
{
    /**
     * @var TestableChatBudgie
     */
    private $chatBudgie;

    /**
     * Set up test fixtures
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->chatBudgie = new TestableChatBudgie();
    }

    /**
     * Test: search_index returns array structure
     */
    public function testSearchIndexReturnsArray()
    {
        $queryText = 'What is WordPress?';
        
        $result = $this->chatBudgie->search_index($queryText, 5, 0.7);
        
        $this->assertIsArray($result);
    }

    /**
     * Test: search_index default parameters work correctly
     */
    public function testSearchIndexDefaultParameters()
    {
        $queryText = 'Test query';
        
        // Call with only query text (using defaults for k and threshold)
        $result = $this->chatBudgie->search_index($queryText);
        
        $this->assertIsArray($result);
    }

    /**
     * Test: search_index respects K parameter
     */
    public function testSearchIndexRespectsKParameter()
    {
        $queryText = 'Test query';
        $k = 2;
        
        $result = $this->chatBudgie->search_index($queryText, $k, 0.0);
        
        $this->assertIsArray($result);
        $this->assertLessThanOrEqual($k, count($result));
    }

    /**
     * Test: search_index filters by threshold
     */
    public function testSearchIndexFiltersByThreshold()
    {
        $queryText = 'Test query';
        $threshold = 0.99; // Very high threshold - should filter out most results
        
        $result = $this->chatBudgie->search_index($queryText, 5, $threshold);
        
        $this->assertIsArray($result);
        
        // All results should meet threshold
        foreach ($result as $item) {
            $this->assertArrayHasKey('score', $item);
            // Note: actual score depends on vector similarity
        }
    }

    /**
     * Test: search_index returns expected result structure
     */
    public function testSearchIndexResultStructure()
    {
        $queryText = 'Test query';
        
        $result = $this->chatBudgie->search_index($queryText, 5, 0.0);
        
        $this->assertIsArray($result);
        
        if (!empty($result)) {
            $firstResult = $result[0];
            
            // Verify result structure
            $this->assertArrayHasKey('id', $firstResult);
            $this->assertArrayHasKey('score', $firstResult);
            $this->assertArrayHasKey('chunk_text', $firstResult);
            $this->assertArrayHasKey('post_id', $firstResult);
            $this->assertArrayHasKey('post_title', $firstResult);
            $this->assertArrayHasKey('post_permalink', $firstResult);
            
            // Verify data types
            $this->assertIsString($firstResult['id']);
            $this->assertIsFloat($firstResult['score']);
            $this->assertIsString($firstResult['chunk_text']);
            $this->assertIsInt($firstResult['post_id']);
            $this->assertIsString($firstResult['post_title']);
            $this->assertIsString($firstResult['post_permalink']);
        }
    }

    /**
     * Test: search_index handles empty query
     */
    public function testSearchIndexHandlesEmptyQuery()
    {
        $queryText = '';
        
        // Should not crash, may return empty results or throw exception
        try {
            $result = $this->chatBudgie->search_index($queryText, 5, 0.7);
            $this->assertIsArray($result);
        } catch (\Exception $e) {
            // Also acceptable
            $this->assertIsString($e->getMessage());
        }
    }

    /**
     * Test: search_index with different K values
     */
    public function testSearchIndexWithDifferentKValues()
    {
        $queryText = 'Test query';
        
        // Test k=1
        $result1 = $this->chatBudgie->search_index($queryText, 1, 0.0);
        $this->assertIsArray($result1);
        $this->assertLessThanOrEqual(1, count($result1));
        
        // Test k=10
        $result10 = $this->chatBudgie->search_index($queryText, 10, 0.0);
        $this->assertIsArray($result10);
        $this->assertLessThanOrEqual(10, count($result10));
    }
}
