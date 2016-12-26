<?php

class AQuery
{
    /**
     * @var array Source array provided
     */
    protected $arr;

    /**
     * @var array - where conditions. Each condition is an array with keys:
     *     field - condition field
     *     value - condition value
     *     operator - safe operators: ==, !=. Other operators are implemented using eval currently
     */
    protected $where;

    /**
     * @var array - sort conditions. Key - field name, value - direction, 1 - asc, -1 - desc
     */
    protected $sort;

    /**
     * @var array - group conditions. Array of field names to group by
     */
    protected $group;

    /**
     * @var array - grouped conditions. Key - field name to group, value - group function.
     *     Supported functions: sum, avg, count, min, max, array
     */
    protected $fields;

    /**
     * @var array - fields to build tree
     */
    protected $tree;

    /**
     * @var string - field name. In case its not empty tree leafs will contain value of field with such name
     */
    protected $lastTreeKey;

    /**
     * @var array - field named to distinct by
     */
    protected $distinctArr;

    /**
     * @var integer - limit
     */
    protected $limitValue;

    /**
     * @var integer - offset
     */
    protected $offsetValue;

    /**
     * @param $array
     * @return AQuery
     */
    public static function model($array)
    {
        return new self($array);
    }

    /**
     * AQuery constructor.
     * @param $array
     * @throws Exception
     */
    public function __construct($array)
    {
        if (!is_array($array))
            throw new Exception('Invalid array');
        $this->arr = $array;
        $this->reset();
    }

    /**
     * Cleanup all conditions, but leave source array
     */
    public function reset()
    {
        $this->where = array();
        $this->sort = array();
        $this->distinctArr = array();
        $this->group = array();
        $this->fields = array();
        $this->tree = array();
        $this->limitValue = 0;
        $this->offsetValue = 0;
    }

    /**
     * Its possible to apply conditions in 2 ways:
     * 1) $a->condition('field1', 'value1')->condition('field2', 'value2')
     * 2) $a->field1('value1')->field2('value2')
     *
     * @param $name
     * @param $arguments
     * @return AQuery
     */
    public function __call($name, $arguments)
    {
        call_user_func_array(array($this, 'condition'), array_merge(array($name),$arguments));
        return $this;
    }

    /**
     * Returns field value, or array of values if there are more than 1 findAll result
     *
     * @param $name
     * @return array|null
     */
    public function __get($name)
    {
        return $this->getValue($name);
    }

    /**
     * Sets field value to all elements found by findAll
     *
     * @param $name
     * @param $value
     */
    public function __set($name, $value)
    {
        $this->setValue($name, $value);
    }

    /**
     * Append filter condition
     *
     * @param $field
     * @param $value
     * @param string $operator
     * @return AQuery
     */
    public function condition($field, $value, $operator='==')
    {
        $this->where[] = array(
            'field'=>$field,
            'value'=>is_array($value) ? $value : array($value),
            'operator'=>$operator
        );
        return $this;
    }

    /**
     * Append sort conditions
     *
     * @param string|array $sort
     * @return AQuery
     */
    public function order($sort)
    {
        if (!is_array($sort))
        {
            $sort = explode(',', $sort);
            $sort = array_map('trim', $sort);
        }
        foreach ($sort as $s)
        {
            list($field, $dir) = explode(' ', $s);
            $dir = ($dir=='desc');
            $this->sort[$field] = $dir;
        }
        return $this;
    }

    /**
     * Append distinct conditions
     *
     * @param string|array $distinct
     * @return AQuery
     */
    public function distinct($distinct)
    {
        if (!is_array($distinct))
        {
            $distinct = explode(',', $distinct);
            $distinct = array_map('trim', $distinct);
        }
        foreach ($distinct as $s)
        {
            $this->distinctArr[$s] = $s;
        }
        return $this;
    }

    /**
     * Set limit
     *
     * @param integer $limit
     * @return AQuery
     */
    public function limit($limit)
    {
        $this->limitValue = $limit;
        return $this;
    }

    /**
     * Set offset
     *
     * @param integer $offset
     * @return AQuery
     */
    public function offset($offset)
    {
        $this->offsetValue = $offset;
        return $this;
    }

    /**
     * Append group conditions
     *
     * @param array|string $group
     * @param array $fields
     * @return AQuery
     */
    public function group($group, $fields)
    {
        if (!is_array($group))
        {
            $group = explode(',', $group);
            $group = array_map('trim', $group);
        }
        $this->group = $group;
        if (!is_array($fields))
        {
            $fields = explode(',', $fields);
            foreach ($fields as &$f)
            {
                preg_match('/(\w+)\((.+)\) *(?:as)? *([A-Z0-9]+)/i', $f, $out);
                $f = (object)array(
                    'agg'=>strtolower($out[1]),
                    'expr'=>$out[2],
                    'key'=>$out[3]
                );
            }
        }
        $this->fields = $fields;
        return $this;
    }

    /**
     * Set tree conditions
     *
     * @param array|string $tree
     * @return AQuery
     */
    public function tree($tree)
    {
        if (!is_array($tree))
        {
            $tree = explode(',', $tree);
            $tree = array_map('trim', $tree);
        }
        $this->tree = $tree;
        return $this;
    }

    /**
     * Set field name. This field will be used to get tree leafs value
     *
     * @param string $key
     * @return AQuery
     */
    public function lastTreeKey($key)
    {
        $this->lastTreeKey = $key;
        return $this;
    }

    /**
     * Return source array
     *
     * @return array
     */
    public function toArray()
    {
        return $this->arr;
    }

    /**
     * Apply all conditions and return result array
     *
     * @return array
     */
    public function findAll()
    {
        $arr = $this->applyFilters();
        if (count($this->group)>0)
            $arr = $this->applyGroup($arr);
        if (count($this->sort)>0)
            usort($arr, array($this, 'applySort'));
        if (count($this->tree)>0)
            $arr = $this->applyTree($arr);
        elseif (count($this->distinctArr)>0)
            $arr = $this->applyDistinct($arr);
        if ($this->limitValue>0 && $this->offsetValue>0)
            $arr = array_slice($arr, $this->offsetValue, $this->limitValue, false);
        $this->reset();
        return $arr;
    }

    /**
     * Apply all conditions and return 1st array element
     *
     * @return mixed
     */
    public function find()
    {
        $arr = $this->applyFilters();
        if (count($this->group)>0)
            $arr = $this->applyGroup($arr);
        if (count($this->sort)>0)
            usort($arr, array($this, 'applySort'));
        if (count($this->tree)>0)
            $arr = $this->applyTree($arr);
        elseif (count($this->distinctArr)>0)
            $arr = $this->applyDistinct($arr);
        if ($this->limitValue>0 && $this->offsetValue>0)
            $arr = array_slice($arr, $this->offsetValue, $this->limitValue, false);
        if (count($arr)>0)
            return reset($arr);
        else
            return null;
    }

    /**
     * @param $name
     * @return mixed
     */
    public function getValue($name)
    {
        $arr = $this->findAll();
        $count = count($arr);
        if ($count==1)
        {
            $arr = reset($arr);
            if (is_object($arr))
                return $arr->$name;
            else
                return $arr[$name];
        }
        elseif ($count==0)
            return null;
        else
        {
            $ret = array();
            foreach ($arr as $v)
            {
                if (is_object($v))
                    $ret[] = $v->$name;
                else
                    $ret[] = $v[$name];
            }
            return $ret;
        }
    }

    /**
     * Filter elements by conditions and set field value in all matched elements
     *
     * @param string $name
     * @param mixed $value
     */
    public function setValue($name, $value)
    {
        $arr = $this->applyFilters();
        foreach ($arr as $k=>$v)
        {
            if (is_object($v))
                $this->arr[$k]->$name = $value;
            else
                $this->arr[$k][$name] = $value;
        }
    }

    /**
     * Count number of elements match conditions
     *
     * @return int
     */
    public function count()
    {
        $arr = $this->applyFilters();
        if (count($this->group)>0)
            $arr = $this->applyGroup($arr);
        if (count($this->tree)>0)
            $arr = $this->applyTree($arr);
        $this->reset();
        return count($arr);
    }

    /**
     * Apply where conditions
     *
     * @return array
     */
    private function applyFilters()
    {
        if (count($this->where)==0)
            return $this->arr;
        $ret = array();
        foreach ($this->arr as $k=>$v)
        {
            if (is_object($v))
            {
                foreach ($this->where as $w)
                {
                    $value = $v->{$w['field']};
                    if ($w['operator']=='==')
                    {
                        if (!in_array($value, $w['value'])) continue 2;
                    }
                    elseif ($w['operator']=='!=')
                    {
                        if (in_array($value, $w['value'])) continue 2;
                    }
                    else
                    {
                        $found = false;
                        foreach ($w['value'] as $wv)
                            if (eval("return $value {$w['operator']} $wv;"))
                            {
                                $found = true;
                                break;
                            }
                        if (!$found) continue 2;
                    }
                }
            }
            else
            {
                foreach ($this->where as $w)
                {
                    $value = $v[$w['field']];
                    if ($w['operator']=='==')
                    {
                        if (!in_array($value, $w['value'])) continue 2;
                    }
                    elseif ($w['operator']=='!=')
                    {
                        if (in_array($value, $w['value'])) continue 2;
                    }
                    else
                    {
                        $found = false;
                        foreach ($w['value'] as $wv)
                            if (eval("return $value {$w['operator']} $wv;"))
                            {
                                $found = true;
                                break;
                            }
                        if (!$found) continue 2;
                    }
                }
            }
            $ret[$k] = $v;
        }
        return $ret;
    }

    /**
     * Build tree
     *
     * @param array $arr
     * @return array
     */
    private function applyTree($arr)
    {
        return $this->applyTreeInternal($arr, 0);
    }

    /**
     * Build tree 1 level and go to next recursively
     *
     * @param array $arr
     * @param integer $level
     * @return array
     */
    private function applyTreeInternal($arr, $level)
    {
        if (!isset($this->tree[$level]))
        {
            if ($this->lastTreeKey)
                if (is_object($arr))
                    return $arr[0]->{$this->lastTreeKey};
                else
                    return $arr[0][$this->lastTreeKey];
            else
                return $arr;
        }
        $key = $this->tree[$level];
        $ret = array();
        foreach ($arr as $v)
        {
            if (is_object($v))
                $ret[$v->$key][] = $v;
            else
                $ret[$v[$key]][] = $v;
        }
        if (count($this->tree) > $level)
        {
            foreach ($ret as &$subarr)
            {
                $subarr = $this->applyTreeInternal($subarr, $level+1);
            }
        }
        return $ret;
    }

    /**
     * Apply group conditions
     *
     * @param array $arr
     * @return array
     */
    private function applyGroup($arr)
    {
        $ret = array();
        foreach ($arr as $a)
        {
            if (is_object($a))
            {
                $hash_arr = array();
                foreach ($this->group as $g)
                {
                    $hash_arr[$g] = $a->$g;
                }
                $hash = md5(json_encode($hash_arr));
                if (!isset($ret[$hash]))
                    $ret[$hash] = $hash_arr;
                $line =& $ret[$hash];
                foreach ($this->fields as $agg)
                {
                    switch ($agg->agg)
                    {
                        case 'sum':
                        case 'avg':
                            $line[$agg->key] += $a->{$agg->expr};
                            break;
                        case 'count':
                            $line[$agg->key] += 1;
                            break;
                        case 'max':
                            $line[$agg->key] = $line[$agg->key]!=null ? max($line[$agg->key], $a->{$agg->expr}) : $a->{$agg->expr};
                            break;
                        case 'min':
                            $line[$agg->key] = $line[$agg->key]!=null ? min($line[$agg->key], $a->{$agg->expr}) : $a->{$agg->expr};
                            break;
                        case 'array':
                            $line[$agg->key][] = $a->{$agg->expr};
                            break;
                    }
                }
            }
            else
            {
                $hash_arr = array();
                foreach ($this->group as $g)
                {
                    $hash_arr[$g] = $a[$g];
                }
                $hash = md5(json_encode($hash_arr));
                if (!isset($ret[$hash]))
                    $ret[$hash] = $hash_arr;
                $line =& $ret[$hash];
                foreach ($this->fields as $agg)
                {
                    switch ($agg->agg)
                    {
                        case 'sum':
                        case 'avg':
                            $line[$agg->key] += $a[$agg->expr];
                            break;
                        case 'count':
                            $line[$agg->key] += 1;
                            break;
                        case 'max':
                            $line[$agg->key] = $line[$agg->key]!=null ? max($line[$agg->key], $a[$agg->expr]) : $a[$agg->expr];
                            break;
                        case 'min':
                            $line[$agg->key] = $line[$agg->key]!=null ? min($line[$agg->key], $a[$agg->expr]) : $a[$agg->expr];
                            break;
                        case 'array':
                            $line[$agg->key][] = $a[$agg->expr];
                            break;
                    }
                }
            }
        }
        $return = array();
        foreach ($ret as $r) $return[] = (object)$r;
        return $return;
    }

    /**
     * Sort callback function
     *
     * @param mixed $a
     * @param mixed $b
     * @return int
     */
    private function applySort($a, $b)
    {
        if (is_object($a))
        {
            foreach ($this->sort as $s=>$d)
                if ($d)
                {
                    if ($a->$s!=$b->$s) return $a->$s > $b->$s ? -1 : 1;
                }
                else
                {
                    if ($a->$s!=$b->$s) return $a->$s < $b->$s ? -1 : 1;
                }
        }
        else
        {
            foreach ($this->sort as $s=>$d)
                if ($d)
                {
                    if ($a[$s]!=$b[$s]) return $a[$s] > $b[$s] ? -1 : 1;
                }
                else
                {
                    if ($a[$s]!=$b[$s]) return $a[$s] < $b[$s] ? -1 : 1;
                }
        }
        return 0;
    }

    /**
     * Apply distinct conditions
     *
     * @param array $arr
     * @return array
     */
    private function applyDistinct($arr)
    {
        $ret = array();
        $values = array();
        foreach ($arr as $d) {
            $item = array();
            if (is_object($d)) {
                foreach ($this->distinctArr as $column) {
                    $item[$column] = $d->$column;
                }
            } else {
                foreach ($this->distinctArr as $column) {
                    $item[$column] = $d[$column];
                }
            }
            $found = false;
            foreach ($values as $oldItem) {
                if ($item == $oldItem) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $values[] = $item;
                $ret[] = $d;
            }
        }
        return $ret;
    }
}