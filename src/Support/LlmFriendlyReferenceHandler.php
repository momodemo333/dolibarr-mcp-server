<?php

declare(strict_types=1);

namespace DolibarrMcp\Support;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolCallException;

/**
 * Decorates the SDK's reference handler so that any exception thrown by a
 * tool surfaces its message to the LLM.
 *
 * The SDK's CallToolHandler only forwards the message of ToolCallException
 * to the client (as an isError tool result); any other Throwable becomes an
 * opaque "Error while executing tool" JSON-RPC error. Our tools throw plain
 * RuntimeExceptions with carefully written, actionable messages (e.g. "API
 * authentication failed: Invalid API key...") that the model needs to see
 * in order to recover — so we convert them here.
 */
final class LlmFriendlyReferenceHandler implements ReferenceHandlerInterface
{
    public function __construct(private readonly ReferenceHandlerInterface $inner)
    {
    }

    public function handle(ElementReference $reference, array $arguments): mixed
    {
        try {
            return $this->inner->handle($reference, $arguments);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($reference instanceof ToolReference) {
                throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
            }
            throw $e;
        }
    }
}
