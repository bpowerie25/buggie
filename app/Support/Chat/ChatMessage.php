<?php

namespace App\Support\Chat;

/**
 * Turning a notice into whatever one chat service wants to be sent.
 *
 * One implementation per provider, and no shared base class on purpose: the two
 * formats have nothing in common beyond both being JSON, and a shared parent would
 * only be somewhere for one service's quirks to leak into the other's output.
 */
interface ChatMessage
{
    /**
     * @param  string  $endpoint  Where this is going. Teams needs it — the same
     *                            product accepts two different card formats
     *                            depending on which kind of URL you were given.
     * @return array<string, mixed>
     */
    public function body(ChatNotice $notice, string $endpoint): array;
}
