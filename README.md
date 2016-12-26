# AQuery

Suppose you have array of arrays, or objects:
```
$arr = [
    ['field1' => 'value11', 'field2' => 'value2', 'field3' => '3'],
    ['field1' => 'value21', 'field2' => 'value2', 'field3' => '2'],
    ['field1' => 'value31', 'field2' => 'value3', 'field3' => '1'],
];
$a = AQuery::model($arr);
```

You can:

1. **filter**: $a->field2('value2')->findAll()
2. **sort**: $a->sort('field3')->findAll()
3. **group**: $a->group('field2', ['field1' => 'array', 'field3' => 'sum'])->findAll()
4. **distinct**: $a->distinct('field2')->findAll()
5. **build tree**: $a->tree('field2')->lastTreeKey('field3')->findAll()
6. **offset and limit**: $a->offset(1)->limit(3)->findAl()
7. **combine any elements from above**: $a->field2('value2')->sort('field3')->tree('field1')->findAll()
8. **get values**: $a->field1('value11')->field3
9. **set values**: $a->field2('value2')->field3 = '123';
