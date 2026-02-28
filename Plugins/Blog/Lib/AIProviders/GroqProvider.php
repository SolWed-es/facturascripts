<?php
namespace FacturaScripts\Plugins\Blog\Lib\AIProviders;

/**
 * Groq AI Provider - FREE tier with 14,400 requests/day
 * Uses Llama 3 models for fast inference
 *
 * Get free API key at: https://console.groq.com/
 *
 * @author Your Name
 */
class GroqProvider
{
    private const API_URL = 'https://api.groq.com/openai/v1/chat/completions';
    private const MODEL = 'llama-3.3-70b-versatile'; // Latest and most capable free model

    /**
     * Generate SEO metadata using Groq AI
     */
    public function generateSEO(string $title, string $content, string $apiKey): array
    {
        $prompt = $this->buildPrompt($title, $content);

        $response = $this->callApi($prompt, $apiKey);

        if (!$response) {
            throw new \Exception('Failed to get response from Groq API');
        }

        return $this->parseResponse($response, $title);
    }

    /**
     * Build the SEO generation prompt
     */
    private function buildPrompt(string $title, string $content): string
    {
        $cleanContent = strip_tags($content);
        $cleanContent = mb_substr($cleanContent, 0, 3000); // Limit content size

        return "You are an SEO expert. Given the following blog post, generate SEO metadata.

Blog Title: {$title}

Content: {$cleanContent}

Generate the following in JSON format:
- meta_title: An SEO-optimized title (max 60 characters)
- meta_description: An engaging meta description (max 160 characters)
- meta_keywords: 5-10 relevant keywords (comma-separated)

Respond ONLY with valid JSON, no markdown formatting:";
    }

    /**
     * Call Groq API
     */
    private function callApi(string $prompt, string $apiKey): ?string
    {
        $data = [
            'model' => self::MODEL,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
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
            throw new \Exception("Groq API error: HTTP {$httpCode}");
        }

        $decoded = json_decode($response, true);

        if (!isset($decoded['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid Groq API response format');
        }

        return $decoded['choices'][0]['message']['content'];
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
