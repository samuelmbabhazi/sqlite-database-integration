<?php

/**
 * Queries from the checked-in MySQL server suite corpus that are known parser
 * gaps today. Keep this list shared between the parser corpus test and parser
 * benchmarks so benchmarks report real regressions instead of these accepted
 * corpus exclusions.
 */
return array(
	'SELECT 1 /*!99999 /* */ */'                       => true,
	'select 1ea10.1a20,1e+ 1e+10 from 1ea10'           => true,
	"聠聡聢聣聤聬聭聮聯聰聲聽隆垄拢陇楼卤潞禄录陆戮 聶職聳聴\n0聲5\n1聲5\n2聲5\n3聲5\n4\n\nSET NAMES gb18030" => true,
	"alter user mysqltest_7@ identified by 'systpass'" => true,
	"SELECT 'a%' LIKE 'a!%' ESCAPE '!', 'a%' LIKE 'a!' || '%' ESCAPE '!'" => true,
	"SELECT 'a%' NOT LIKE 'a!%' ESCAPE '!', 'a%' NOT LIKE 'a!' || '%' ESCAPE '!'" => true,
	"SELECT 'a%' LIKE 'a!%' ESCAPE '$', 'a%' LIKE 'a!' || '%' ESCAPE '$'" => true,
	"SELECT 'a%' NOT LIKE 'a!%' ESCAPE '$', 'a%' NOT LIKE 'a!' || '%' ESCAPE '$'" => true,
	'ALTER SCHEMA s1 READ ONLY DEFAULT'                => true,
);
