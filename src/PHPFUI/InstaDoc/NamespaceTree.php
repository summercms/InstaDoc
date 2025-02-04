<?php

namespace PHPFUI\InstaDoc;

class NamespaceTree
	{
	private static string $activeClass;

	private static string $activeNamespace;

	/**
	 * @var array<string, NamespaceTree> indexed by namespace part containing a NamespaceTree
	 */
	private array $children = [];

	/**
	 * @var array<string, string> indexed by fully qualified class name containing the file name
	 */
	private array $classes = [];

	private static \PHPFUI\InstaDoc\Controller $controller;

	/**
	 * @var bool true if this namespace is in the local git repo
	 */
	private bool $localGit = false;

	/**
	 * @var array<string, bool> of unique markdown files indexed by file name
	 */
	private array $md = [];

	/**
	 * @var string of the namespace part
	 */
	private string $namespace = '';

	/**
	 * @var NamespaceTree our parent
	 */
	private ?\PHPFUI\InstaDoc\NamespaceTree $parent = null;

	private static ?\PHPFUI\InstaDoc\NamespaceTree $root = null;

	// only we can make us to ensure the tree is good
	private function __construct()
		{
		}

	public static function addGlobalNameSpaceClass(string $filename, bool $localGit = false) : void
		{
		$filenameLength = \strlen($filename);

		if (\str_ends_with($filename, '.php'))
			{
			$root = self::getRoot();
			$file = \str_replace('/', '\\', \str_replace('.php', '', $filename));
			$parts = \explode('\\', $file);
			$class = \array_pop($parts);
			$root->classes[$class] = $filename;
			$root->localGit = $localGit;
			}
		}

	public static function addNamespace(string $namespace, string $directory, bool $localGit = false) : void
		{
		$namespaceLength = \strlen($namespace);

		if ($namespaceLength && '\\' == $namespace[$namespaceLength - 1])
			{
			$namespace = \substr($namespace, 0, $namespaceLength - 1);
			}

		$node = self::findNamespace($namespace);
		$node->localGit = $localGit;

		try
			{
			$iterator = new \DirectoryIterator($directory);
			}
		catch (\Throwable)
			{
			$iterator = [];
			}

		foreach ($iterator as $fileinfo)
			{
			$filename = $fileinfo->getFilename();
			$filenameLength = \strlen($filename);

			if ($fileinfo->isDir() && ! \str_contains($filename, '.'))
				{
				self::addNamespace($namespace . '\\' . $filename, $directory . '/' . $filename, $localGit);
				}
			elseif (\str_ends_with($filename, '.php'))
				{
				$class = \substr($filename, 0, $filenameLength - 4);
				$class = $namespace . '\\' . $class;
				$file = $directory . '/' . $filename;
				$file = \str_replace('//', '/', $file);
				$node->classes[$class] = $file;
				}
			elseif (\strpos($filename, '.md') == $filenameLength - 3)
				{
				$node->md[$directory . '/' . $filename] = true;
				}
			}
		}

	public static function deleteNameSpace(string $namespace) : void
		{
		$deleteThis = self::findNamespace($namespace);
		unset($deleteThis->parent->children[$deleteThis->namespace], $deleteThis);
		}

	public static function findNamespace(string $namespace) : NamespaceTree
		{
		$node = self::getRoot();

		if (! \strlen($namespace))
			{
			return $node;
			}

		$parts = \explode('\\', $namespace);

		foreach ($parts as $part)
			{
			if (empty($node->children[$part]))
				{
				$child = new NamespaceTree();
				$child->namespace = $part;
				$node->children[$part] = $child;
				$child->parent = $node;
				}
			$node = $node->children[$part];
			}

		return $node;
		}

	/**
	 * @return array<string, string> all classes
	 */
  public static function getAllClasses(?NamespaceTree $tree = null) : array
		{
		if (! $tree)
			{
			$tree = self::getRoot();
			}

		$classes = [];

		foreach ($tree->children as $child)
			{
			$classes = \array_merge($classes, self::getAllClasses($child));
			}

		$namespace = $tree->getNamespace();

		foreach ($tree->classes as $class => $path)
			{
			$classes[$path] = $class;
			}

		return $classes;
		}

	/**
	 * @return array<string>
	 */
	public static function getAllMDFiles(?NamespaceTree $tree = null) : array
		{
		if (! $tree)
			{
			$tree = self::getRoot();
			}
		$files = $tree->getMDFiles();

		foreach ($tree->children as $child)
			{
			$files = \array_merge($files, self::getAllMDFiles($child));
			}

		return $files;
		}

	/**
	 * @return array<string, NamespaceTree> indexed by namespace part containing a NamespaceTree
	 */
	public function getChildren() : array
		{
		return $this->children;
		}

	/**
	 * @return array<string, string> an array with full paths of all the classes in the
	 * namespace, indexed by class name
	 */
  public function getClassFilenames() : array
		{
		return $this->classes;
		}

	public function getGit() : bool
		{
		return $this->localGit;
		}

	/**
	 * @return array<string> md file names
	 */
	public function getMDFiles() : array
		{
		return \array_keys($this->md);
		}

	/**
	 * Returns the full namespace all the way up to the root.
	 */
	public function getNamespace() : string
		{
		$namespace = $this->namespace;

		$tree = $this->parent;

		while ($tree && $namespace)
			{
			$namespace = $tree->namespace . '\\' . $namespace;
			$tree = $tree->parent;
			}

		return $namespace;
		}

	public static function hasClass(string $namespacedClass) : bool
		{
		$node = self::getRoot();
		$parts = \explode('\\', $namespacedClass);
		$class = \array_pop($parts);

		foreach ($parts as $part)
			{
			if (empty($node->children[$part]))
				{
				return false;
				}
			$node = $node->children[$part];
			}
		$classes = $node->getClassFilenames();

		return isset($classes[$namespacedClass]);
		}

	public static function load(string $file) : bool
		{
		if (! \file_exists($file))
			{
			return false;
			}

		$contents = \file_get_contents($file);
		$temp = \unserialize($contents);

		if (! $temp)
			{
			return false;
			}

		self::$root = $temp;

		return true;
		}

	/**
	 * Populates a menu object with namespaces as sub menus and
	 * classes as menu items.
	 */
  public static function populateMenu(\PHPFUI\Menu $menu) : void
		{
		self::sort(self::getRoot());

		// add no namespace stuff first
		if (self::$root->classes)
			{
			$namespace = '\\';
			$rootMenu = new \PHPFUI\Menu();

			foreach (self::$root->classes as $class => $path)
				{
				$activeClass = self::$activeClass;
				$activeNamespace = self::$activeNamespace;

				$menuItem = new \PHPFUI\MenuItem(\str_replace('\\', '<wbr>\\', $class), self::$controller->getClassUrl($class));

				if ($class == self::$activeClass)
					{
					$menuItem->setActive();
					}
				$rootMenu->addMenuItem($menuItem);
				}
			$menuItem = new \PHPFUI\MenuItem($namespace);
			$menu->addSubMenu($menuItem, $rootMenu);
			}

		foreach (self::$root->children as $child)
			{
			$child->getMenuTree($child, $menu);
			}
	}

	public static function save(string $file) : bool
		{
		return \file_put_contents($file, \serialize(self::$root)) > 0;
		}

	/**
	 * Set the currently active class for menu generation.
	 */
	public static function setActiveClass(string $activeClass) : void
		{
		self::$activeClass = $activeClass;
		}

	/**
	 * Set the currently active namespace for menu generation.
	 */
	public static function setActiveNamespace(string $activeNamespace) : void
		{
		if (\strlen($activeNamespace) && '\\' != $activeNamespace[0])
			{
			$activeNamespace = '\\' . $activeNamespace;
			}

		self::$activeNamespace = $activeNamespace;
		}

	/**
	 * Set the Controller. Used for creating links so all
	 * documentation is at the same url.
	 */
	public static function setController(Controller $controller) : void
		{
		self::$controller = $controller;
		}

	/**
	 * Sorts the child namespaces and classes
	 */
	public static function sort(?NamespaceTree $tree = null) : void
		{
		if (! $tree)
			{
			$tree = self::getRoot();
			}
		\ksort($tree->classes, SORT_FLAG_CASE | SORT_STRING);
		\ksort($tree->children, SORT_FLAG_CASE | SORT_STRING);

		foreach ($tree->children as &$child)
			{
			self::sort($child);
			}
		}

	private function getMenuTree(NamespaceTree $tree, \PHPFUI\Menu $menu) : \PHPFUI\Menu
		{
		$currentMenu = new \PHPFUI\Menu();

		// Get all the child menus for the current tree so they appear first as sub menus
		foreach ($tree->children as $child)
			{
			$namespace = $child->getNamespace();

			if (\count(self::getAllClasses($child)))
				{
				$menuItem = new \PHPFUI\MenuItem('\\' . $child->namespace);

				if ($namespace == self::$activeNamespace)
					{
					$menuItem->setActive();
					}
				$this->getMenuTree($child, $currentMenu);
				}
			}
		$namespace = $tree->getNamespace();

		// Get all the normal menu items after the child submenus
		foreach ($tree->classes as $class => $path)
			{
			$parts = \explode('\\', $class);
			$baseClass = \array_pop($parts);

			$menuItem = new \PHPFUI\MenuItem(\str_replace('\\', '<wbr>\\', $baseClass), self::$controller->getClassUrl($class));

			if ($baseClass == self::$activeClass && $namespace == self::$activeNamespace)
				{
				$menuItem->setActive();
				}
			$currentMenu->addMenuItem($menuItem);
			}
		$menuItem = new \PHPFUI\MenuItem('\\' . $tree->namespace);
		$menu->addSubMenu($menuItem, $currentMenu);

		return $currentMenu;
		}

	private static function getRoot() : NamespaceTree
		{
		if (! self::$root)
			{
			self::$root = new NamespaceTree();
			}

		return self::$root;
		}
	}
