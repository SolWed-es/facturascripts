<?php
namespace FacturaScripts\Plugins\Blog\Lib\AIProviders;

/**
 * Cohere AI Provider - FREE tier with 1000 API calls/month
 * Uses Command models for text generation
 *
 * Get free API key at: https://dashboard.cohere.com/
 *
 * @author Your Name
 */
class CohereProvider
{
    private const API_URL = 'https://api.cohere.ai/v1/chat';
    private const MODEL = 'command-r'; // Latest free model

    /**
     * Generate SEO metadata using Cohere AI
     */
    public function generateSEO(string $title, string $content, string $apiKey): array
    {
        $prompt = $this->buildPrompt($title, $content);

        $response = $this->callApi($prompt, $apiKey);

        if (!$response) {
            throw new \Exception('Failed to get response from Cohere API');
        }

        return $this->parseResponse($response, $title);
    }

    /**
     * Build the SEO generation prompt
     */
    private function buildPrompt(string $title, string $content): string
    {
        $cleanContent = strip_tags($content);
        $cleanContent = mb_substr($cleanContent, 0, 3000);

        return "You are an SEO expert. Generate SEO metadata for this blog post.

Title: {$title}

Content: {$cleanContent}

Respond ONLY with valid JSON (no markdown):
{
  \"meta_title\": \"SEO-optimized title (max 60 chars)\",
  \"meta_description\": \"Engaging description (max 160 chars)\",
  \"meta_keywords\": \"keyword1, keyword2, keyword3\"
}";
    }

    /**
     * Call Cohere API
     */
    private function callApi(string $prompt, string $apiKey): ?string
    {
        $data = [
            'model' => self::MODEL,
            'message' => $prompt,
            'temperature' => 0.3,
            'max_tokens' => 500
        ];

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \Exception("Cohere API error: HTTP {$httpCode}");
        }

        $decoded = json_decode($response, true);

        if (!isset($decoded['text'])) {
            throw new \Exception('Invalid Cohere API response format');
        }

        return $decoded['text'];
    }

    /**
     * Parse AI response and extract SEO fields
     */
    private function parseResponse(string $response, string $fallbackTitle): array
    {
        // Clean markdown formatting if present
        $response = preg_replace('/```json\s*/', '', $response);
        $response = preg_replace('/```\s*$/', '', $response);
        $response = trim($response);

        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new \Exception('AI response is not valid JSON');
        }

        return [
            'meta_title' => $data['meta_title'] ?? $fallbackTitle,
            'meta_description' => $data['meta_description'] ?? '',
            'meta_keywords' => $data['meta_keywords'] ?? ''
        ];
    }
}
