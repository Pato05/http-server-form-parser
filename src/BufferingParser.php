<?php

namespace Amp\Http\Server\FormParser;

use Amp\Http\InvalidHeaderException;
use Amp\Http\Rfc7230;
use Amp\Http\Server\Request;
use Amp\Promise;
use Amp\Success;
use function Amp\call;

/**
 * This class parses submitted forms from incoming request bodies in application/x-www-form-urlencoded and
 * multipart/form-data format.
 */
final class BufferingParser
{
    /** @var int Prevent requests from creating arbitrary many fields causing lot of processing time */
    private $fieldCountLimit;

    public function __construct(int $fieldCountLimit = null)
    {
        $this->fieldCountLimit = $fieldCountLimit ?? (int) \ini_get('max_input_vars') ?: 1000;
    }

    /**
     * Consumes the request's body and parses it.
     *
     * If the content-type doesn't match the supported form content types, the body isn't consumed.
     *
     * @param Request $request
     *
     * @return Promise
     */
    public function parseForm(Request $request): Promise
    {
        $type = $request->getHeader('content-type');
        $body = $request->getBody();
        $boundary = $this->parseContentType($type);
        if ($boundary === null) {
            return new Success(new Form([]));
        }

        return call(function () use ($body, $boundary) {
            return $this->parseBody(yield $body->buffer(), $boundary);
        });
    }

    /**
     * Parses the given body string, using the given boundary.
     *
     * @param string      $body
     * @param string      $boundary
     *
     * @return Form
     * @throws ParseException
     */
    public function parseBody(string $body, string $boundary = ''): Form
    {
        // If there's no boundary, we're in urlencoded mode.
        if ($boundary === '') {
            $fields = [];

            foreach (\explode("&", $body, $this->fieldCountLimit) as $pair) {
                $pair = \explode("=", $pair, 2);
                $field = \urldecode($pair[0]);
                $value = \urldecode($pair[1] ?? "");

                $fields[$field][] = $value;
            }

            if (\strpos($pair[1] ?? "", "&") !== false) {
                throw new ParseException("Maximum number of variables exceeded");
            }

            return new Form($fields);
        }

        $fields = $files = [];

        // RFC 7578, RFC 2046 Section 5.1.1
        if (\strncmp($body, "--$boundary\r\n", \strlen($boundary) + 4) !== 0) {
            return new Form([]);
        }

        $exp = \explode("\r\n--$boundary\r\n", $body, $this->fieldCountLimit);
        $exp[0] = \substr($exp[0], \strlen($boundary) + 4);
        $exp[\count($exp) - 1] = \substr(\end($exp), 0, -\strlen($boundary) - 8);

        foreach ($exp as $entry) {
            if (($position = \strpos($entry, "\r\n\r\n")) === false) {
                throw new ParseException("No header/body boundary found");
            }

            try {
                $headers = Rfc7230::parseHeaders(\substr($entry, 0, $position + 2));
            } catch (InvalidHeaderException $e) {
                throw new ParseException("Invalid headers in body part", 0, $e);
            }

            $entry = \substr($entry, $position + 4);

            $count = \preg_match(
                '#^\s*form-data(?:\s*;\s*(?:name\s*=\s*"([^"]+)"|filename\s*=\s*"([^"]*)"))+\s*$#',
                $headers["content-disposition"][0] ?? "",
                $matches
            );

            if (!$count || !isset($matches[1])) {
                throw new ParseException("Missing or invalid content disposition");
            }

            // Ignore Content-Transfer-Encoding as deprecated and hence we won't support it

            $name = $matches[1];
            $contentType = $headers["content-type"][0] ?? "text/plain";

            if (isset($matches[2])) {
                $files[$name][] = new File($matches[2], $entry, $contentType);
            } else {
                $fields[$name][] = $entry;
            }
        }

        if (\strpos($entry ?? "", "--$boundary") !== false) {
            throw new ParseException("Maximum number of variables exceeded");
        }

        return new Form($fields, $files);
    }

    /**
     * Parse the given content-type and returns the boundary if parsing is supported,
     * an empty string if we are in url-encoded mode or null if not supported.
     *
     * @param null|string $contentType
     *
     * @return null|string
     */
    public function parseContentType(?string $contentType)
    {
        if ($contentType !== null && \strncmp($contentType, "application/x-www-form-urlencoded", \strlen("application/x-www-form-urlencoded"))) {
            if (!\preg_match('#^\s*multipart/(?:form-data|mixed)(?:\s*;\s*boundary\s*=\s*("?)([^"]*)\1)?$#', $contentType, $matches)) {
                return null;
            }

            return $matches[2];
        }
        return '';
    }
}
