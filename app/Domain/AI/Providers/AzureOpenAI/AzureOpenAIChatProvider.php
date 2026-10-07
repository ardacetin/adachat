<?php

namespace App\Domain\AI\Providers\AzureOpenAI;

use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Exceptions\ProviderUnavailable;
use App\Domain\AI\Providers\OpenAI\OpenAIChatProvider;

/**
 * Azure OpenAI v1 API: the OpenAI Responses API of the institution's own
 * Azure resource (base URL https://<resource>.openai.azure.com/openai/v1, no
 * api-version), authenticated with the resource's key in the api-key header.
 * The model is the deployment name. Input tokens are estimated (no count
 * endpoint is documented) and the hosted web search is not offered.
 */
final class AzureOpenAIChatProvider extends OpenAIChatProvider
{
    protected function name(): string
    {
        return 'azure_openai';
    }

    protected function headers(): array
    {
        return ['api-key' => $this->apiKey];
    }

    public function count(ChatRequest $request): int
    {
        throw new ProviderUnavailable('azure_openai: no token count endpoint');
    }
}
