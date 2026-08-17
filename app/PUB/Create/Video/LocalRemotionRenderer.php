<?php
declare(strict_types=1);

namespace App\PUB\Create\Video;

/**
 * LOCAL REMOTION VIDEO RENDERER
 *
 * Local implementation of VideoRendererInterface.
 *
 * Purpose:
 *   Hide all local Remotion complexity behind one PUB component.
 *
 * Responsibilities:
 *   - accept a composition key
 *   - accept a complete props/render-plan payload
 *   - write any temporary props/recipe file needed locally
 *   - invoke the local Node/Remotion renderer
 *   - return the resulting MP4 file information
 *
 * This class should be the ONLY PUB layer that knows the render
 * is happening locally rather than on the production server.
 *
 * Human workflow must remain:
 *
 *   Analyze -> Create
 *
 * The user should never need to manually:
 *   - run a Node script
 *   - remember a Remotion command
 *   - locate recipe files
 *   - locate temporary render output
 *
 * Later, this implementation can be replaced by a
 * ServerRemotionRenderer without changing format Creators.
 */
final class LocalRemotionRenderer implements VideoRendererInterface
{
}