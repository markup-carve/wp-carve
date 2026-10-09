<?php

declare(strict_types=1);

namespace WpCarve\Test;

use PHPUnit\Framework\TestCase;
use function countDeclaredPairs;

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
}
