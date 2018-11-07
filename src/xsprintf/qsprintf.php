<?php

/**
 * Format an SQL query. This function behaves like `sprintf`, except that all
 * the normal conversions (like "%s") will be properly escaped, and additional
 * conversions are supported:
 *
 *   %nd, %ns, %nf, %nB
 *     "Nullable" versions of %d, %s, %f and %B. Will produce 'NULL' if the
 *     argument is a strict null.
 *
 *   %=d, %=s, %=f
 *     "Nullable Test" versions of %d, %s and %f. If you pass a value, you
 *     get "= 3"; if you pass null, you get "IS NULL". For instance, this
 *     will work properly if `hatID' is a nullable column and $hat is null.
 *
 *       qsprintf($escaper, 'WHERE hatID %=d', $hat);
 *
 *   %Ld, %Ls, %Lf, %LB
 *     "List" versions of %d, %s, %f and %B. These are appropriate for use in
 *     an "IN" clause. For example:
 *
 *       qsprintf($escaper, 'WHERE hatID IN (%Ld)', $list_of_hats);
 *
 *   %B ("Binary String")
 *     Escapes a string for insertion into a pure binary column, ignoring
 *     tests for characters outside of the basic multilingual plane.
 *
 *   %C, %LC ("Column")
 *     Escapes a column name or a list of column names.
 *
 *   %K ("Comment")
 *     Escapes a comment.
 *
 *   %Q, %LA, %LO, %LQ ("Query Fragment")
 *     Injects a query fragment from a prior call to qsprintf(). The list
 *     variants join a list of query fragments with AND, OR, or comma.
 *
 *   %R ("Database and Table Reference")
 *     Behaves like "%T.%T" and prints a full reference to a table including
 *     the database. Accepts a AphrontDatabaseTableRefInterface.
 *
 *   %P ("Password or Secret")
 *     Behaves like "%s", but shows "********" when the query is printed in
 *     logs or traces. Accepts a PhutilOpaqueEnvelope.
 *
 *   %~ ("Substring")
 *     Escapes a substring query for a LIKE (or NOT LIKE) clause. For example:
 *
 *       //  Find all rows with $search as a substring of `name`.
 *       qsprintf($escaper, 'WHERE name LIKE %~', $search);
 *
 *     See also %> and %<.
 *
 *   %> ("Prefix")
 *     Escapes a prefix query for a LIKE clause. For example:
 *
 *       //  Find all rows where `name` starts with $prefix.
 *       qsprintf($escaper, 'WHERE name LIKE %>', $prefix);
 *
 *   %< ("Suffix")
 *     Escapes a suffix query for a LIKE clause. For example:
 *
 *       //  Find all rows where `name` ends with $suffix.
 *       qsprintf($escaper, 'WHERE name LIKE %<', $suffix);
 *
 *   %T ("Table")
 *     Escapes a table name. In most cases, you should use "%R" instead.
 */
function qsprintf(PhutilQsprintfInterface $escaper, $pattern /* , ... */) {
  $args = func_get_args();
  array_shift($args);
  return new PhutilQueryString($escaper, $args);
}

function vqsprintf(PhutilQsprintfInterface $escaper, $pattern, array $argv) {
  array_unshift($argv, $pattern);
  return new PhutilQueryString($escaper, $argv);
}

/**
 * @{function:xsprintf} callback for encoding SQL queries. See
 * @{function:qsprintf}.
 */
function xsprintf_query($userdata, &$pattern, &$pos, &$value, &$length) {
  $type = $pattern[$pos];

  if (is_array($userdata)) {
    $escaper = $userdata['escaper'];
    $unmasked = $userdata['unmasked'];
  } else {
    $escaper = $userdata;
    $unmasked = false;
  }

  $next = (strlen($pattern) > $pos + 1) ? $pattern[$pos + 1] : null;
  $nullable = false;
  $done = false;

  $prefix   = '';

  if (!($escaper instanceof PhutilQsprintfInterface)) {
    throw new InvalidArgumentException(pht('Invalid database escaper.'));
  }

  switch ($type) {
    case '=': // Nullable test
      switch ($next) {
        case 'd':
        case 'f':
        case 's':
          $pattern = substr_replace($pattern, '', $pos, 1);
          $length  = strlen($pattern);
          $type    = 's';
          if ($value === null) {
            $value = 'IS NULL';
            $done = true;
          } else {
            $prefix = '= ';
            $type = $next;
          }
          break;
        default:
          throw new Exception(
            pht(
              'Unknown conversion, try %s, %s, or %s.',
              '%=d',
              '%=s',
              '%=f'));
      }
      break;

    case 'n': // Nullable...
      switch ($next) {
        case 'd': //  ...integer.
        case 'f': //  ...float.
        case 's': //  ...string.
        case 'B': //  ...binary string.
          $pattern = substr_replace($pattern, '', $pos, 1);
          $length = strlen($pattern);
          $type = $next;
          $nullable = true;
          break;
        default:
          throw new XsprintfUnknownConversionException("%n{$next}");
      }
      break;

    case 'L': // List of..
      qsprintf_check_type($value, "L{$next}", $pattern);
      $pattern = substr_replace($pattern, '', $pos, 1);
      $length  = strlen($pattern);
      $type = 's';
      $done = true;

      switch ($next) {
        case 'd': //  ...integers.
          $value = implode(', ', array_map('intval', $value));
          break;
        case 'f': // ...floats.
          $value = implode(', ', array_map('floatval', $value));
          break;
        case 's': // ...strings.
          foreach ($value as $k => $v) {
            $value[$k] = "'".$escaper->escapeUTF8String((string)$v)."'";
          }
          $value = implode(', ', $value);
          break;
        case 'B': // ...binary strings.
          foreach ($value as $k => $v) {
            $value[$k] = "'".$escaper->escapeBinaryString((string)$v)."'";
          }
          $value = implode(', ', $value);
          break;
        case 'C': // ...columns.
          foreach ($value as $k => $v) {
            $value[$k] = $escaper->escapeColumnName($v);
          }
          $value = implode(', ', $value);
          break;
        case 'Q':
          foreach ($value as $k => $v) {
            $value[$k] = $v->getUnmaskedString();
          }
          $value = implode(', ', $value);
          break;
        case 'O':
          foreach ($value as $k => $v) {
            $value[$k] = $v->getUnmaskedString();
          }
          $value = '(('.implode(') OR (', $value).'))';
          break;
        case 'A':
          foreach ($value as $k => $v) {
            $value[$k] = $v->getUnmaskedString();
          }
          $value = '(('.implode(') AND (', $value).'))';
          break;
        default:
          throw new XsprintfUnknownConversionException("%L{$next}");
      }
      break;
  }

  if (!$done) {
    qsprintf_check_type($value, $type, $pattern);
    switch ($type) {
      case 's': // String
        if ($nullable && $value === null) {
          $value = 'NULL';
        } else {
          $value = "'".$escaper->escapeUTF8String((string)$value)."'";
        }
        $type = 's';
        break;

      case 'B': // Binary String
        if ($nullable && $value === null) {
          $value = 'NULL';
        } else {
          $value = "'".$escaper->escapeBinaryString((string)$value)."'";
        }
        $type = 's';
        break;

      case 'Q': // Query Fragment
        if ($value instanceof PhutilQueryString) {
          $value = $value->getUnmaskedString();
        }
        $type = 's';
        break;

      case '~': // Like Substring
      case '>': // Like Prefix
      case '<': // Like Suffix
        $value = $escaper->escapeStringForLikeClause($value);
        switch ($type) {
          case '~': $value = "'%".$value."%'"; break;
          case '>': $value = "'".$value."%'"; break;
          case '<': $value = "'%".$value."'"; break;
        }
        $type  = 's';
        break;

      case 'f': // Float
        if ($nullable && $value === null) {
          $value = 'NULL';
        } else {
          $value = (float)$value;
        }
        $type = 's';
        break;

      case 'd': // Integer
        if ($nullable && $value === null) {
          $value = 'NULL';
        } else {
          $value = (int)$value;
        }
        $type = 's';
        break;

      case 'T': // Table
      case 'C': // Column
        $value = $escaper->escapeColumnName($value);
        $type = 's';
        break;

      case 'K': // Komment
        $value = $escaper->escapeMultilineComment($value);
        $type = 's';
        break;

      case 'R': // Database + Table Reference
        $database_name = $value->getAphrontRefDatabaseName();
        $database_name = $escaper->escapeColumnName($database_name);

        $table_name = $value->getAphrontRefTableName();
        $table_name = $escaper->escapeColumnName($table_name);

        $value = $database_name.'.'.$table_name;
        $type = 's';
        break;

      case 'P': // Password or Secret
        if ($unmasked) {
          $value = $value->openEnvelope();
          $value = "'".$escaper->escapeUTF8String($value)."'";
        } else {
          $value = '********';
        }
        $type = 's';
        break;

      default:
        throw new XsprintfUnknownConversionException($type);
    }
  }

  if ($prefix) {
    $value = $prefix.$value;
  }

  $pattern[$pos] = $type;
}

function qsprintf_check_type($value, $type, $query) {
  switch ($type) {
    case 'Ld':
    case 'Ls':
    case 'LC':
    case 'LB':
    case 'Lf':
    case 'LQ':
    case 'LA':
    case 'LO':
      if (!is_array($value)) {
        throw new AphrontParameterQueryException(
          $query,
          pht('Expected array argument for %%%s conversion.', $type));
      }
      if (empty($value)) {
        throw new AphrontParameterQueryException(
          $query,
          pht('Array for %%%s conversion is empty.', $type));
      }

      foreach ($value as $scalar) {
        qsprintf_check_scalar_type($scalar, $type, $query);
      }
      break;
    default:
      qsprintf_check_scalar_type($value, $type, $query);
      break;
  }
}

function qsprintf_check_scalar_type($value, $type, $query) {
  switch ($type) {
    case 'LQ':
    case 'LA':
    case 'LO':
      if (!($value instanceof PhutilQueryString)) {
        throw new AphrontParameterQueryException(
          $query,
          pht(
            'Expected a list of PhutilQueryString objects for %%%s '.
            'conversion.',
            $type));
      }
      break;

    case 'Q':
      // TODO: See T13217. Remove this eventually.
      if (is_string($value)) {
        phlog(
          pht(
            'UNSAFE: Raw string ("%s") passed to query ("%s") for "%%Q" '.
            'conversion. %%Q should be passed a query string.',
            $value,
            $query));
        break;
      }

      if (!($value instanceof PhutilQueryString)) {
        throw new AphrontParameterQueryException(
          $query,
          pht('Expected a PhutilQueryString for %%%s conversion.', $type));
      }
      break;

    case 'LC':
    case 'T':
    case 'C':
      if (!is_string($value)) {
        throw new AphrontParameterQueryException(
          $query,
          pht('Expected a string for %%%s conversion.', $type));
      }
      break;

    case 'Ld':
    case 'Lf':
    case 'd':
    case 'f':
      if (!is_null($value) && !is_numeric($value)) {
        throw new AphrontParameterQueryException(
          $query,
          pht('Expected a numeric scalar or null for %%%s conversion.', $type));
      }
      break;

    case 'Ls':
    case 's':
    case 'LB':
    case 'B':
    case '~':
    case '>':
    case '<':
    case 'K':
      if (!is_null($value) && !is_scalar($value)) {
        throw new AphrontParameterQueryException(
          $query,
          pht('Expected a scalar or null for %%%s conversion.', $type));
      }
      break;

    case 'R':
      if (!($value instanceof AphrontDatabaseTableRefInterface)) {
        throw new AphrontParameterQueryException(
          pht(
            'Parameter to "%s" conversion in "qsprintf(...)" is not an '.
            'instance of AphrontDatabaseTableRefInterface.',
            '%R'));
      }
      break;

    case 'P':
      if (!($value instanceof PhutilOpaqueEnvelope)) {
        throw new AphrontParameterQueryException(
          pht(
            'Parameter to "%s" conversion in "qsprintf(...)" is not an '.
            'instance of PhutilOpaqueEnvelope.',
            '%P'));
      }
      break;

    default:
      throw new XsprintfUnknownConversionException($type);
  }
}
