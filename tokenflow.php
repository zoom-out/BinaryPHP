<?php
	/**
	 * Code parser/generator class.
	 *
	 * Takes in the tokenizer object, parses it, and generates C++ code.
	 *
	 * @version $Revision: 1.63 $
	 * @access public
	 * @package BinaryPHP
	 */
	class Generator
	{
		/**
		 * Holds the classes index.
		 * @param array
		 * @access public
		 */
		var $classes = array();
		/**
		 * Holds the functions index.
		 * @param array
		 * @access public
		 */
		var $functions = array();
		/**
		 * Holds the globals used in the script.
		 * @param array
		 * @access public
		 */
		var $globals = array();
		/**
		 * Holds the tokens (as generated by the tokenizer class).
		 * @param array
		 * @access public
		 */
		var $tokens;
		/**
		 * Holds the current token pointer.
		 * @param int
		 * @access public
		 */
		var $token = 0;
		/**
		 * An array of each function of the code.
		 * @param array
		 * @access public
		 */
		var $code = array();
		/**
		 * The current line of code.
		 * @param string
		 * @access public
		 */
		var $buffer;
		/**
		 * The current function
		 * @param int
		 * @access public
		 */
		var $curfunction;
		/**
		 * Current namespace
		 * @param string
		 * @access public
		 */
		var $namespace;
		/**
		 * An array of includes.
		 * @param array
		 * @access public
		 */
		var $includes = array();
		/**
		 * An array of C++ includes.
		 * @param array
		 * @access public
		 */
		var $cppincludes = array();
		/**
		 * An array of libraries.
		 * @param array
		 * @access public
		 */
		var $libs = array();
		/**
		 * Variable scope
		 * @param array
		 * @access private
		 */
		var $scope = array();
		/**
		 * Tab count
		 * @param int
		 * @access private
		 */
		var $tabs;
		/**
		 * Temporary tab count
		 * @param bool
		 * @access private
		 */
		var $temptabs;
		/**
		 * Array of the current hierarchy of encapsulating language constructs.
		 * @param array
		 * @access private
		 */
		var $in = array();

		/**
		 * Parses the flow of tokens generated by the tokenizer.
		 *
		 * @param object $tokenizer The tokenizer object.
		 * @access public
		 */
		function Generator(&$tokenizer)
		{
			$this->curfunction = 0;
			$this->tokens = $tokenizer->tokens;
			$this->functions[0] = array('main', 'int', array(array('int', 'argc'), array('char**', 'argv')));
			/*
			 * First element of each array in the functions array is the name of the function.
			 * The second element is the return type of this function.
			 * The third argument is an array of arguments in the form array(type, name).
			 */
			$this->scope[0] = array();
			$this->tabs = 0;
			$this->temptabs = 0;
			$this->Parse_Tokenstream();
		}
		/**
		 * Parses the flow of tokens generated by the tokenizer.
		 *
		 * @param mixed $break What token to break parameters and move on from.
		 * @param mixed $end The token to stop parsing at.  This can be a string literal, or an int corresponding to a token.
		 * @param bool $for If being called from a for.
		 * @return array
		 * @access private
		 */
		function Parse_Tokenstream($break = null, $end = null, $for = false)
		{
			if($this->token >= count($this->tokens))
				return false;
			$code = (string) null;
			$params = array();
			for(; $this->token < count($this->tokens); ++$this->token)
			{
				list($token, $data) = $this->tokens[$this->token];
				if($end != null && ($token == $end || (is_array($end) && in_array($token, $end))))
				{
					if(!$for && $this->token < count($this->tokens) - 2)
					{
						if($data == null)
							$code .= $token;
						else
							$code .= $data;
					}
					if($for)
						--$this->token;
					break;
				}
				if($break != null && ($token == $break || (is_array($break) && in_array($token, $break))))
				{
					$params[] = $code;
					$code = (string) null;
					continue;
				}
				switch($token)
				{
					case T_CLASS:
						++$this->token;
						$this->AddCode('class ' . $this->tokens[$this->token][1]);
						$this->in[] = 'class';
						break;
					case T_ECHO:
						++$this->token;
						if($break == null && $end == null)
							$this->B_echo($this->Parse_Tokenstream(array('.', ','), ';', true), true);
						else
							$code .= $this->B_echo($this->Parse_Tokenstream(array('.', ','), ';', true));
							++$this->token;
						break;
					case ',':
						$code .= ', ';
						break;
					case ';':
						$code .= ';';
						if($end == null && $break == null)
						{
							$this->AddCode($code);
							$code = (string) null;
						}
						break;
					case T_CONSTANT_ENCAPSED_STRING:
						$code .= '"' . str_replace(array('\\\'', '"'), array('\'', '\\"'), substr($data, 1, -1)) . '"';
						break;
					case T_VARIABLE:
						$this->token += 1;
						if($break == null && $end == null)
							$this->B_var($data, $this->Parse_Tokenstream(null, array(';', ')', ','), $for), true);
						else
							$code .= $this->B_var($data, $this->Parse_Tokenstream(null, array(';', ')', ','), $for));
						break;
					case T_LNUMBER:
					case T_DNUMBER:
						$code .= $data;
						break;
					case T_STRING:
						if($this->tokens[$this->token + 1][0] == '(')
						{
							++$this->token;
							if($break == null && $end == null)
							{
								$this->B_function_call($data, $this->Parse_Tokenstream(',', ')', $for), true);
								++$this->token;
							}
							else
								$code .= $this->B_function_call($data, $this->Parse_Tokenstream(',', ')', $for));
						}
						else
							$code .= $data;
						break;
					case T_NEW:
						$code .= 'new ';
						break;
					case T_OBJECT_OPERATOR:
						$code .= '->';
						break;
					case T_IF:
						++$this->token;
						if($break == null && $end == null)
							$this->B_if($this->Parse_Tokenstream(null, ')', $for), true);
						else
							$code .= $this->B_if($this->Parse_Tokenstream(null, ')', $for));
						break;
					case T_ELSEIF:
						++$this->token;
						if($break == null && $end == null)
							$this->B_elseif($this->Parse_Tokenstream(null, ')', $for), true);
						else
							$code .= $this->B_elseif($this->Parse_Tokenstream(null, ')', $for));
						break;
					case T_ELSE:
						if($break == null && $end == null)
							$this->B_else( true);
						else
							$code .= $this->B_else();
						break;
					case T_FOR:
						++$this->token;
						if($break == null && $end == null)
							$this->B_for($this->Parse_Tokenstream(';', ')', true), true);
						else
							$code .= $this->B_for($this->Parse_Tokenstream(';', ')', true));
						break;
					case T_FUNCTION:
						$data = $this->tokens[$this->token + 1][1];
						$this->token += 3;
						if($break == null && $end == null)
							$this->B_function($data, $this->Parse_Tokenstream(',', ')', $for), true);
						else
							$code .= $this->B_function($data, $this->Parse_Tokenstream(',', ')', $for));
						++$this->token;
						break;
					case T_ARRAY:
						$data = $this->tokens[$this->token + 1][1];
						++$this->token;
						$code .=  $this->B_array($this->Parse_Tokenstream(',', ')', $for));
						break;
					case '-':
					case '+':
					case '*':
					case '/':
					case '^':
					case '&':
					case '|':
					case '%':
					case '<':
					case '=':
						$code .= ' ' . $token . ' ';
						break;
					case T_DOUBLE_ARROW:
						$code .= ' => ';
						break;
					case '[':
					case ']':
						$code .= $token;
						break;
					case T_BOOLEAN_AND:
					case T_BOOLEAN_OR:
					case T_IS_EQUAL:
						$code .= ' ' . $data . ' ';
						break;
					case T_INC:
					case T_DEC:
						$code .= $data;
						break;
					case '{':
						$this->AddCode('{');
						++$this->tabs;
						break;
					case '}':
						if(!isset($this->in[count($this->in) - 1]))
						{
							--$this->tabs;
							$this->AddCode('}');
							break;
						}
						switch($this->in[count($this->in) - 1])
						{
							case 'class':
								--$this->tabs;
								$this->AddCode('};');
								break;
							case 'function':
								$this->curfunction = 0;
								break;
							default:
								--$this->tabs;
								$this->AddCode('}');
								break;
						}
						unset($this->in[count($this->in) - 1]);
						break;
				}
			}
			$params[] = $code;
			$code = (string) null;
			return $params;
		}
		/**
		 * Code generator for variable declarations
		 *
		 * @param string $var Variable name.
		 * @param array $val Value of the variable.
		 * @param bool $add Set to true to add the code to the source.
		 * @return string
		 * @access private
		 */
		function B_var($var, $val, $add = false)
		{
			$var = '_' . substr($var, 1);
			if($this->Define($var))
				$code = 'php_var ';
			else
				$code = (string) null;
			if(substr($val[0], 0, 2) == '->')
				$var = 'OBJECT(' . $var . ', foo)';
			$code .= $var . implode(' ', $val);
			if($add)
				$this->AddCode($code);
			return $code;
		}
		/**
		 * Code generator for echo
		 *
		 * @param array $parameters Things to echo.
		 * @param bool $add Set to true to add the code to the source.
		 * @return string
		 * @access private
		 */
		function B_echo($parameters, $add = false)
		{
			$this->namespace = 'std';
			$this->AddInclude('iostream');
			$code = 'cout';
			foreach($parameters as $param)
			{
				if($param == '"\n"')
					$code .= ' << endl';
				else
					$code .= ' << ' . $param;
			}
			if($add)
				$this->AddCode($code . ';');
			return $code;
		}
		/**
		 * Code generator for function definitions.
		 *
		 * @param string $function Name of the function being defined.
		 * @param array $parameters Parameters to the function.
		 * @param bool $add Set to true to add the code to the source.
		 * @return string
		 * @access private
		 */
		function B_function($function, $params)
		{
			$params[count($params) - 1] = substr($params[count($params) - 1], 0, -1);
			$this->curfunction = count($this->functions);
			$func = array($function, 'void', array());
			foreach($params as $param)
				$func[2][] = array('php_var', substr($param, 8));
			$this->functions[$this->curfunction] = $func;
			$this->scops[$this->curfunction] = array();
			$this->in[] = 'function';
		}
		/**
		 * Code generator for function calls
		 *
		 * @param string $function Name of the function being called.
		 * @param array $parameters Parameters to the function.
		 * @param bool $add Set to true to add the code to the source.
		 * @return string
		 * @access private
		 */
		function B_function_call($function, $parameters, $add = false)
		{
			global $funcs;
			if($function == '_cpp')
			{
				$parameters[count($parameters) - 1] = substr($parameters[count($parameters) - 1], 0, -1);
				$code = substr($parameters[0], 1, -1);
				if($add)
					$this->AddCode($code);
				return $code;
			}
			elseif(isset($funcs[$function]))
			{
				if(count($funcs[$function]) == 2)
				{
					list($inc, $cppinc) = $funcs[$function];
					$lib = null;
				}
				else
					list($inc, $cppinc, $lib) = $funcs[$function];
				if($inc != null)
					$this->AddInclude($inc);
				if($cppinc != null)
					$this->AddCPPInclude($cppinc);
				if($lib != null)
					$this->AddLib($lib);
			}

			if($function == 'header')
				$code = $this->B_header($parameters);
			else
				$code = $function . '(' . implode(', ', $parameters);
			if($add)
				$this->AddCode($code . ';');
			return $code;
		}
		/**
		 * Code generator for calls to the array() language construct.
		 *
		 * @param array $parameters Elements of the array.
		 * @return string
		 * @access private
		 */
		function B_array($parameters)
		{
			$this->AddCPPInclude('arrays/array.cpp');
			foreach($parameters as $key => $val)
			{
				$arr = explode('=>', $val);
				if(count($arr) == 1)
					$parameters[$key] = 'NULL, (void *)(php_var *) &(php_var(' . $val . '))';
				else
					$parameters[$key] = '(void *)(php_var *) &(php_var(' . trim($arr[0]) . ')), (void *)(php_var *) &(php_var(' . trim($arr[1]) . '))';
			}
			return 'array(' . count($parameters) . ', ' . implode(', ', $parameters);
		}
		/**
		 * Code generator for IFs
		 *
		 * @param array $parameters Statement(s).
		 * @param bool $add Set to true to add the code to the source.
		 * @return string
		 * @access private
		 */
		function B_if($parameters, $add = false)
		{
			$code = 'if(' . $parameters[0];
			if($add)
				$this->AddCode($code);
			if($this->tokens[$this->token + 1][0] != '{')
			{
				++$this->tabs;
				++$this->temptabs;
			}
			return $code;
		}
		/**
		 * Code generator for ELSEIFs
		 *
		 * @param array $parameters Statement(s).
		 * @param bool $add Set to true to add the code to the source.
		 * @return string
		 * @access private
		 */
		function B_elseif($parameters, $add = false)
		{
			$code = 'else if(' . $parameters[0];
			if($add)
				$this->AddCode($code);
			if($this->tokens[$this->token + 1][0] != '{')
			{
				++$this->tabs;
				++$this->temptabs;
			}
			return $code;
		}
		/**
		 * Code generator for ELSEs
		 *
		 * @param bool $add Set to true to add the code to the source.
		 * @return string
		 * @access private
		 */
		function B_else($add = false)
		{
			$code = 'else';
			if($add)
				$this->AddCode($code);
			if($this->tokens[$this->token + 1][0] != '{')
			{
				++$this->tabs;
				++$this->temptabs;
			}
			return $code;
		}
		/**
		 * Code generator for FOR loops
		 *
		 * @param array $parameters Statement(s).
		 * @param bool $add Set to true to add the code to the source.
		 * @return string
		 * @access private
		 */
		function B_for($parameters, $add = false)
		{
			$code = 'for(' . $parameters[0] . '; ' . $parameters[1] . '; ' . $parameters[2] . ')';
			if($add)
				$this->AddCode($code);
			if(isset($this->tokens[$this->token + 1][0]) && $this->tokens[$this->token + 1][0] != '{')
			{
				++$this->tabs;
				++$this->temptabs;
			}
			return $code;
		}
		/**
		 * Code generator for headers
		 *
		 * @param array $parameters Parameters to the function.
		 * @return string
		 * @access private
		 */
		function B_header($parameters)
		{
			$header_count = 0;
			$this->current_header += 1;
			foreach($this->tokens as $token)
			{
				if((array_search('header', $token)) != false)
					$header_count += 1;
			}

			$code = 'cout << ' . substr($parameters[0], 0, -1);
			if($this->current_header == $header_count)
				$code .= ' << endl << endl;';
			else
				$code .= ' << endl;';
			return $code;
		}
		/**
		 * Adds a line of code to the current function.
		 *
		 * @param string $code Line of code to add.
		 * @param int $function Function to add the code to.
		 * @access private
		 */
		function AddCode($code, $function = null)
		{
			if($function == null)
				$function = $this->curfunction;
			$this->code[$function][] = str_repeat("\t", $this->tabs + 1) . $code;
			if($this->temptabs > 0)
			{
				--$this->temptabs;
				--$this->tabs;
			}
		}
		/**
		 * Add an include.
		 *
		 * @param string $inc Name of file to include.
		 * @access public
		 */
		function AddInclude($inc)
		{
			if(is_array($inc))
			{
				foreach($inc as $in)
				{
					if(!in_array($in, $this->includes))
						$this->includes[] = $in;
				}
			}
			elseif(!in_array($inc, $this->includes))
					$this->includes[] = $inc;
		}
		/**
		 * Add a C++ include.
		 *
		 * @param string $inc Name of file to include.
		 * @access public
		 */
		function AddCPPInclude($inc)
		{
			if(is_array($inc))
			{
				foreach($inc as $in)
				{
					if(!in_array($in, $this->cppincludes))
						$this->cppincludes[] = $in;
				}
			}
			elseif(!in_array($inc, $this->cppincludes))
					$this->cppincludes[] = $inc;
		}
		/**
		 * Add an include.
		 *
		 * @param string $inc Name of file to include.
		 * @access public
		 */
		function AddLib($inc)
		{
			if(is_array($inc))
			{
				foreach($inc as $in)
				{
					if(!in_array($in, $this->libs))
						$this->libs[] = $in;
				}
			}
			elseif(!in_array($inc, $this->libs))
					$this->libs[] = $inc;
		}
		/**
		 * Checks the variable scope to see if a given variable is declared in this scope or not.
		 *
		 * @param string $var Name of variable.
		 * @param int $function Function to search in.
		 * @return bool
		 * @access public
		 */
		function IsDefined($var, $function = null)
		{
			if($function == null)
				$function = $this->curfunction;
			return in_array($var, $this->scope[$function]);
		}
		/**
		 * Adds a variable to the current scope.
		 *
		 * @param string $var Name of variable.
		 * @param int $function Function to add to.
		 * @return bool
		 * @access public
		 */
		function Define($var, $function = null)
		{
			if($function == null)
				$function = $this->curfunction;
			if($this->IsDefined($var, $function))
				return false;
			$this->scope[$function][] = $var;
			return true;
		}
		/**
		 * Organizes and outputs proper C++ code.
		 *
		 * @return string
		 * @access public
		 */
		function Convert()
		{
			$code = (string) null;
			foreach($this->includes as $include)
			{
				if(file_exists('functions/' . $include))
					$code .= '#include "functions/' . $include . '"' . "\n";
				else
					$code .= '#include <' . $include . '>' . "\n";
			}
			if(!empty($this->namespace))
				$code .= 'using namespace ' . $this->namespace . ';' . "\n";
			$code .= '#include "php_var.hpp"' . "\n";
			foreach($this->cppincludes as $cpp)
				$code .= implode(null, file('functions/' . $cpp)) . "\n\n";
			foreach($this->code as $func => $arr)
			{
				list($name, $return, $args) = $this->functions[$func];
				$code .= $return . ' ' . $name . '(';
				$args2 = array();
				foreach($args as $arg)
					$args2[] = $arg[0] . ' ' . $arg[1];
				$code .= implode(', ', $args2) . ')' . "\n";
				$code .= '{' . "\n";
				foreach($arr as $line)
					$code .= $line . "\n";
				$code .= '}' . "\n";
			}
			$flags = (string) null;
			foreach($this->libs as $lib)
				$flags .= '-l' . $lib . ' ';
			return array($code, $flags);
		}
	}
?>
