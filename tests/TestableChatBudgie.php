<?php

namespace ChatBudgie\Tests;

/**
 * Testable version of ChatBudgie class
 * Mocks WordPress functions and focuses on search_index logic
 */
class TestableChatBudgie
{
    private static int $embeddingDimension = 1536;
    private static string $embeddingAPI = 'https://chat.superbudgie.com/api/rag/embedding/v1';

    /**
     * Mock get_embedding method for testing
     * In real implementation, this calls the embedding API
     * 
     * @param string $title
     * @param string $content
     * @param string $excerpt
     * @return array
     * @throws \Exception
     */
    public function get_embedding($title, $content, $excerpt)
    {
        // This is a mock - in tests, we'll override this method
        $testVector = array_fill(0, self::$embeddingDimension, 0.5);
        
        return [
            [
                'chunkText' => $content,
                'embedding' => $testVector
            ]
        ];
    }

    /**
     * Search the vector index for similar content
     * Embeds the query text and returns top K chunks with scores above the threshold
     *
     * @param string $query_text The search query text
     * @param int $k Maximum number of results to return (default: 5)
     * @param float $threshold Minimum similarity score threshold (default: 0.7)
     * @return array Array of results containing 'id', 'score', and 'chunk_text'
     * @throws \Exception If embedding or search fails
     */
    public function search_index($query_text, $k = 5, $threshold = 0.7)
    {
        try {
            // Embed the query text
            $embedding_data = $this->get_embedding('Search Query', $query_text, '');

            // Get the first chunk's embedding as the query vector
            if (empty($embedding_data) || !isset($embedding_data[0]['embedding'])) {
                throw new \Exception('Failed to generate query embedding');
            }

            $query_vector = $embedding_data[0]['embedding'];

            // Initialize Searcher
            $searcher = new \ChatBudgie\Vektor\Services\Searcher();

            // Search for top K results (oversample to handle threshold filtering)
            $results = $searcher->search($query_vector, $k * 2, false);

            // Filter by threshold and limit to K
            $filtered_results = array();
            foreach ($results as $result) {
                if ($result['score'] >= $threshold) {
                    // Parse chunk_id to get post_id (format: postID_chunkIndex)
                    $chunk_id = $result['id'];
                    $parts = explode('_', $chunk_id);
                    $post_id = intval($parts[0]);

                    // Mock get_post - in real implementation, this calls WordPress
                    $post = $this->mock_get_post($post_id);
                    $chunk_text = $post ? $post->post_content : '';

                    $filtered_results[] = array(
                        'id' => $chunk_id,
                        'score' => $result['score'],
                        'chunk_text' => $chunk_text,
                        'post_id' => $post_id,
                        'post_title' => $post ? $post->post_title : '',
                        'post_permalink' => $post ? $this->mock_get_permalink($post_id) : ''
                    );

                    if (count($filtered_results) >= $k) {
                        break;
                    }
                }
            }

            return $filtered_results;

        } catch (\Exception $e) {
            error_log('ChatBudgie search_index error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Mock get_post for testing
     */
    private function mock_get_post($post_id)
    {
        // Return mock post data
        return (object) array(
            'ID' => $post_id,
            'post_title' => 'Test Post ' . $post_id,
            'post_content' => 'This is test content for post ' . $post_id,
        );
    }

    /**
     * Mock get_permalink for testing
     */
    private function mock_get_permalink($post_id)
    {
        return 'https://example.com/?p=' . $post_id;
    }
}
