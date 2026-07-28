<?php

declare(strict_types=1);

namespace DolibarrMcp\Sql;

/**
 * Fail-closed first pass over raw SQL text, run before any parsing.
 *
 * It answers one question only: is this a single statement whose text holds no
 * construct that would let a parser and the MySQL server disagree on what will
 * be executed? It knows nothing about SQL semantics — tables, columns and
 * functions are the validator's job.
 *
 * Two parser behaviours make this pass mandatory rather than merely useful:
 *  - a trailing statement after ";" is silently merged into the parse tree;
 *  - the body of an executable comment is dropped by the parser while the
 *    server runs it.
 */
class SqlLexer
{
    private const STATE_DEFAULT = 0;
    private const STATE_SINGLE_QUOTE = 1;
    private const STATE_DOUBLE_QUOTE = 2;
    private const STATE_BACKTICK = 3;
    private const STATE_LINE_COMMENT = 4;
    private const STATE_BLOCK_COMMENT = 5;

    public function __construct(private int $maxLength = 8000)
    {
    }

    /**
     * @throws SqlValidationException when the text is not a single safe statement
     */
    public function assertSingleReadOnlyStatement(string $sql): void
    {
        if (!mb_check_encoding($sql, 'UTF-8')) {
            throw new SqlValidationException(
                'SQL_INVALID_ENCODING',
                'The query is not valid UTF-8.'
            );
        }

        if (mb_strlen($sql, 'UTF-8') > $this->maxLength) {
            throw new SqlValidationException(
                'SQL_TOO_LONG',
                sprintf('The query exceeds the maximum length of %d characters.', $this->maxLength)
            );
        }

        $state = self::STATE_DEFAULT;
        $length = strlen($sql);
        $sawSignificant = false;
        $sawStatementEnd = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            switch ($state) {
                case self::STATE_SINGLE_QUOTE:
                case self::STATE_DOUBLE_QUOTE:
                    $quote = $state === self::STATE_SINGLE_QUOTE ? "'" : '"';
                    if ($char === '\\') {
                        $i++; // the escaped character cannot close the literal
                        break;
                    }
                    if ($char === $quote) {
                        if ($next === $quote) {
                            $i++; // doubled quote stays inside the literal
                            break;
                        }
                        $state = self::STATE_DEFAULT;
                    }
                    break;

                case self::STATE_BACKTICK:
                    if ($char === '`') {
                        if ($next === '`') {
                            $i++;
                            break;
                        }
                        $state = self::STATE_DEFAULT;
                    }
                    break;

                case self::STATE_LINE_COMMENT:
                    if ($char === "\n") {
                        $state = self::STATE_DEFAULT;
                    }
                    break;

                case self::STATE_BLOCK_COMMENT:
                    // MySQL does not nest block comments, but a special comment
                    // opening inside one must still be refused: the parser and
                    // the server would not read the same text.
                    if ($char === '/' && $next === '*') {
                        $this->assertNotSpecialComment(substr($sql, $i + 2, 2));
                    }
                    if ($char === '*' && $next === '/') {
                        $i++;
                        $state = self::STATE_DEFAULT;
                    }
                    break;

                default:
                    if ($char === '/' && $next === '*') {
                        $this->assertNotSpecialComment(substr($sql, $i + 2, 2));
                        $i++;
                        $state = self::STATE_BLOCK_COMMENT;
                        break;
                    }
                    if ($char === '#') {
                        $state = self::STATE_LINE_COMMENT;
                        break;
                    }
                    // "--" only opens a comment when followed by whitespace or
                    // end of input; "1--2" is a subtraction, and treating it as
                    // a comment would hide a trailing statement from this pass.
                    if ($char === '-' && $next === '-') {
                        $after = $i + 2 < $length ? $sql[$i + 2] : '';
                        if ($after === '' || $after === ' ' || $after === "\t" || $after === "\n" || $after === "\r") {
                            $i++;
                            $state = self::STATE_LINE_COMMENT;
                            break;
                        }
                    }
                    if ($char === "'") {
                        $sawSignificant = true;
                        $this->assertNoTrailingStatement($sawStatementEnd);
                        $state = self::STATE_SINGLE_QUOTE;
                        break;
                    }
                    if ($char === '"') {
                        $sawSignificant = true;
                        $this->assertNoTrailingStatement($sawStatementEnd);
                        $state = self::STATE_DOUBLE_QUOTE;
                        break;
                    }
                    if ($char === '`') {
                        $sawSignificant = true;
                        $this->assertNoTrailingStatement($sawStatementEnd);
                        $state = self::STATE_BACKTICK;
                        break;
                    }
                    if ($char === ';') {
                        $sawStatementEnd = true;
                        break;
                    }
                    if ($char !== ' ' && $char !== "\t" && $char !== "\n" && $char !== "\r") {
                        $this->assertNoTrailingStatement($sawStatementEnd);
                        $sawSignificant = true;
                    }
                    break;
            }
        }

        if ($state === self::STATE_SINGLE_QUOTE || $state === self::STATE_DOUBLE_QUOTE) {
            throw new SqlValidationException(
                'SQL_UNTERMINATED_STRING',
                'The query contains an unterminated string literal.'
            );
        }
        if ($state === self::STATE_BACKTICK) {
            throw new SqlValidationException(
                'SQL_UNTERMINATED_IDENTIFIER',
                'The query contains an unterminated quoted identifier.'
            );
        }
        if ($state === self::STATE_BLOCK_COMMENT) {
            throw new SqlValidationException(
                'SQL_UNTERMINATED_COMMENT',
                'The query contains an unterminated block comment.'
            );
        }

        if (!$sawSignificant) {
            throw new SqlValidationException('SQL_EMPTY', 'The query is empty.');
        }
    }

    /**
     * Return the statement with string literals, quoted identifiers and
     * comments blanked out, preserving length.
     *
     * Some constructs never reach the parse tree in a usable form — the parser
     * swallows `FOR SHARE` into a table alias, for instance — so they can only
     * be caught in the text. Doing that on the raw statement would flag
     * `WHERE nom = 'FOR UPDATE'`; doing it here cannot, because everything
     * inside quotes is already gone.
     *
     * Assumes the statement passed assertSingleReadOnlyStatement() first.
     */
    public function maskLiteralsAndComments(string $sql): string
    {
        $state = self::STATE_DEFAULT;
        $length = strlen($sql);
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            switch ($state) {
                case self::STATE_SINGLE_QUOTE:
                case self::STATE_DOUBLE_QUOTE:
                    $quote = $state === self::STATE_SINGLE_QUOTE ? "'" : '"';
                    $out .= ' ';
                    if ($char === '\\') {
                        $out .= ' ';
                        $i++;
                        break;
                    }
                    if ($char === $quote) {
                        if ($next === $quote) {
                            $out .= ' ';
                            $i++;
                            break;
                        }
                        $state = self::STATE_DEFAULT;
                    }
                    break;

                case self::STATE_BACKTICK:
                    $out .= ' ';
                    if ($char === '`') {
                        if ($next === '`') {
                            $out .= ' ';
                            $i++;
                            break;
                        }
                        $state = self::STATE_DEFAULT;
                    }
                    break;

                case self::STATE_LINE_COMMENT:
                    $out .= $char === "\n" ? "\n" : ' ';
                    if ($char === "\n") {
                        $state = self::STATE_DEFAULT;
                    }
                    break;

                case self::STATE_BLOCK_COMMENT:
                    $out .= ' ';
                    if ($char === '*' && $next === '/') {
                        $out .= ' ';
                        $i++;
                        $state = self::STATE_DEFAULT;
                    }
                    break;

                default:
                    if ($char === '/' && $next === '*') {
                        $out .= '  ';
                        $i++;
                        $state = self::STATE_BLOCK_COMMENT;
                        break;
                    }
                    if ($char === '#') {
                        $out .= ' ';
                        $state = self::STATE_LINE_COMMENT;
                        break;
                    }
                    if ($char === '-' && $next === '-') {
                        $after = $i + 2 < $length ? $sql[$i + 2] : '';
                        if ($after === '' || $after === ' ' || $after === "\t" || $after === "\n" || $after === "\r") {
                            $out .= '  ';
                            $i++;
                            $state = self::STATE_LINE_COMMENT;
                            break;
                        }
                    }
                    if ($char === "'" || $char === '"' || $char === '`') {
                        $out .= ' ';
                        $state = $char === "'"
                            ? self::STATE_SINGLE_QUOTE
                            : ($char === '"' ? self::STATE_DOUBLE_QUOTE : self::STATE_BACKTICK);
                        break;
                    }
                    $out .= $char;
                    break;
            }
        }

        return $out;
    }

    private function assertNoTrailingStatement(bool $sawStatementEnd): void
    {
        if ($sawStatementEnd) {
            throw new SqlValidationException(
                'SQL_MULTI_STATEMENT',
                'Only one statement is allowed per call.'
            );
        }
    }

    /**
     * Refuse the block-comment forms the server treats as instructions.
     *
     * All are read by the server and dropped by the parser, so they are the one
     * place where "what was validated" and "what runs" can diverge:
     *
     *  - `/*!` executable comments carry arbitrary SQL;
     *  - `/*M!` is MariaDB's own spelling of the same thing;
     *  - `/*+` optimizer hints carry directives, and MAX_EXECUTION_TIME(0) or
     *    SET_VAR(...) can switch off the very statement timeout the gateway
     *    installs — a limit removed by a string the parser never reported.
     *
     * @param string $marker Up to two characters following the comment opener
     *
     * @throws SqlValidationException
     */
    private function assertNotSpecialComment(string $marker): void
    {
        $first = $marker !== '' ? $marker[0] : '';
        $second = strlen($marker) > 1 ? $marker[1] : '';

        if ($first === '!' || (strtolower($first) === 'm' && $second === '!')) {
            throw new SqlValidationException(
                'SQL_EXECUTABLE_COMMENT',
                'Executable comments (/*! ... */ and /*M! ... */) are not allowed.'
            );
        }
        if ($first === '+') {
            throw new SqlValidationException(
                'SQL_OPTIMIZER_HINT',
                'Optimizer hints (/*+ ... */) are not allowed: they can override server-side limits.'
            );
        }
    }
}
