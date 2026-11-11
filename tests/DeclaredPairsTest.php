<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\TestCase;
use function countDeclaredPairs;
use function declaredPairInputs;

require_once __DIR__ . '/../scripts/lib/declared-pairs.php';

class DeclaredPairsTest extends TestCase
{
    public function testCountsEveryCarveFenceInACompareBlock(): void
    {
        $page = <<<'MD'
            ```carve
            outside any block
            ```

            ::: compare

            ```carve
            one
            ```

            ```html
            <p>one</p>
            ```

            ````carve
            ```carve
            nested, not a pair
            ```
            ````

            ```html
            <pre></pre>
            ```

            ```carve
            three
            ```

            ```html
            <p>three</p>
            ```

            :::
            MD;

        $this->assertSame(3, countDeclaredPairs($page));
    }

    public function testCountsEachDistinctInputOnce(): void
    {
        $page = <<<'MD'
            ::: compare

            ```carve
            same
            ```

            ```html
            <p>same</p>
            ```

            ```carve
            same
            ```

            ```html
            <p>same</p>
            ```

            :::

            ::: compare crlf

            ```carve
            same
            ```

            ```html
            <p>same</p>
            ```

            :::

            ::: compare no-render

            ```carve
            other
            ```

            ```html
            ```

            :::
            MD;

        $this->assertSame(4, countDeclaredPairs($page));
        $this->assertCount(3, declaredPairInputs($page));
    }
}
