<?php
 namespace Predis\Command; class HashSetMultiple extends Command { public function getId() { return 'HMSET'; } protected function filterArguments(array $arguments) { if (count($arguments) === 2 && is_array($arguments[1])) { $flattenedKVs = array($arguments[0]); $args = $arguments[1]; foreach ($args as $k => $v) { $flattenedKVs[] = $k; $flattenedKVs[] = $v; } return $flattenedKVs; } return $arguments; } } 