<?php
session_start();
require_once 'db.php';

// Authentication check
$user = isset($_SESSION['user_id']) ? $pdo->query("SELECT * FROM users WHERE id = " . $_SESSION['user_id'])->fetch() : null;

// Handle signup
if (isset($_POST['signup'])) {
    $username = $_POST['username'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role'];
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
    $stmt->execute([$username, $email, $password, $role]);
    header("Location: index.php?section=login");
    exit;
}

// Handle login
if (isset($_POST['login'])) {
    $email = $_POST['email'];
    $password = $_POST['password'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        header("Location: index.php");
        exit;
    } else {
        $error = "Invalid credentials";
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Handle product creation
if (isset($_POST['add_product']) && $user && $user['role'] == 'seller') {
    $name = $_POST['name'];
    $description = $_POST['description'];
    $price = $_POST['price'];
    $stock = $_POST['stock'];
    $category_id = $_POST['category_id'];
    $image_url = $_POST['image_url'];
    $stmt = $pdo->prepare("INSERT INTO products (user_id, category_id, name, description, price, stock, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user['id'], $category_id, $name, $description, $price, $stock, $image_url]);
    header("Location: index.php?section=manage_products");
    exit;
}

// Handle product deletion
if (isset($_GET['delete_product']) && $user && $user['role'] == 'seller') {
    $product_id = $_GET['delete_product'];
    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ? AND user_id = ?");
    $stmt->execute([$product_id, $user['id']]);
    header("Location: index.php?section=manage_products");
    exit;
}

// Handle add to cart
if (isset($_POST['add_to_cart']) && $user) {
    $product_id = $_POST['product_id'];
    $quantity = $_POST['quantity'];
    $stmt = $pdo->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)");
    $stmt->execute([$user['id'], $product_id, $quantity]);
    header("Location: index.php?section=cart");
    exit;
}

// Handle checkout
if (isset($_POST['checkout']) && $user) {
    $cart_items = $pdo->query("SELECT c.*, p.price, p.name FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = " . $user['id'])->fetchAll();
    $total = 0;
    foreach ($cart_items as $item) {
        $total += $item['price'] * $item['quantity'];
    }
    $stmt = $pdo->prepare("INSERT INTO orders (user_id, total) VALUES (?, ?)");
    $stmt->execute([$user['id'], $total]);
    $order_id = $pdo->lastInsertId();
    foreach ($cart_items as $item) {
        $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
        $stmt->execute([$order_id, $item['product_id'], $item['quantity'], $item['price']]);
    }
    $pdo->query("DELETE FROM cart WHERE user_id = " . $user['id']);
    header("Location: index.php?section=order_confirmation");
    exit;
}

// Fetch products for homepage
$products = $pdo->query("SELECT p.*, c.name as category_name FROM products p JOIN categories c ON p.category_id = c.id ORDER BY p.created_at DESC LIMIT 8")->fetchAll();

// Fetch categories
$categories = $pdo->query("SELECT * FROM categories")->fetchAll();

// Handle search and filters
$search_query = isset($_GET['search']) ? $_GET['search'] : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$price_min = isset($_GET['price_min']) ? $_GET['price_min'] : '';
$price_max = isset($_GET['price_max']) ? $_GET['price_max'] : '';

$product_query = "SELECT p.*, c.name as category_name FROM products p JOIN categories c ON p.category_id = c.id WHERE 1=1";
if ($search_query) {
    $product_query .= " AND (p.name LIKE '%$search_query%' OR c.name LIKE '%$search_query%')";
}
if ($category_filter) {
    $product_query .= " AND c.id = $category_filter";
}
if ($price_min) {
    $product_query .= " AND p.price >= $price_min";
}
if ($price_max) {
    $product_query .= " AND p.price <= $price_max";
}
$filtered_products = $pdo->query($product_query)->fetchAll();

// Fetch cart items
$cart_items = $user ? $pdo->query("SELECT c.*, p.name, p.price, p.image_url FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = " . $user['id'])->fetchAll() : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etsy Clone</title>
    <style>
        /* General Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }

        body {
            background-color: #f5f5f5;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        header {
            background-color: #f1641e;
            color: white;
            padding: 10px 0;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        header .container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        header h1 {
            font-size: 24px;
        }

        nav a {
            color: white;
            text-decoration: none;
            margin: 0 15px;
            font-size: 16px;
        }

        nav a:hover {
            text-decoration: underline;
        }

        .section {
            display: none;
        }

        .section.active {
            display: block;
        }

        /* Homepage Styles */
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .product-card {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }

        .product-card:hover {
            transform: translateY(-5px);
        }

        .product-card img {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }

        .product-card h3 {
            font-size: 18px;
            padding: 10px;
        }

        .product-card p {
            padding: 0 10px 10px;
            color: #666;
        }

        .product-card .price {
            font-weight: bold;
            color: #f1641e;
            padding: 0 10px 10px;
        }

        /* Form Styles */
        form {
            max-width: 400px;
            margin: 20px auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        form input, form select, form textarea {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        form button {
            background: #f1641e;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
        }

        form button:hover {
            background: #d35400;
        }

        /* Search and Filter Styles */
        .search-filter {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .search-filter input, .search-filter select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        /* Cart Styles */
        .cart-item {
            display: flex;
            gap: 20px;
            background: white;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 8px;
        }

        .cart-item img {
            width: 100px;
            height: 100px;
            object-fit: cover;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }

            .search-filter {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Etsy Clone</h1>
            <nav>
                <a href="#" onclick="showSection('home')">Home</a>
                <?php if ($user): ?>
                    <a href="#" onclick="showSection('profile')">Profile</a>
                    <?php if ($user['role'] == 'seller'): ?>
                        <a href="#" onclick="showSection('manage_products')">Manage Products</a>
                    <?php endif; ?>
                    <a href="#" onclick="showSection('cart')">Cart</a>
                    <a href="index.php?logout=1">Logout</a>
                <?php else: ?>
                    <a href="#" onclick="showSection('login')">Login</a>
                    <a href="#" onclick="showSection('signup')">Signup</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <div class="container">
        <!-- Home Section -->
        <div id="home" class="section active">
            <h2>Featured Products</h2>
            <div class="search-filter">
                <form method="GET">
                    <input type="text" name="search" placeholder="Search products..." value="<?php echo htmlspecialchars($search_query); ?>">
                    <select name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="price_min" placeholder="Min Price" value="<?php echo htmlspecialchars($price_min); ?>">
                    <input type="number" name="price_max" placeholder="Max Price" value="<?php echo htmlspecialchars($price_max); ?>">
                    <button type="submit">Filter</button>
                </form>
            </div>
            <div class="product-grid">
                <?php foreach ($filtered_products as $product): ?>
                    <div class="product-card">
                        <img src="<?php echo htmlspecialchars($product['image_url'] ?: 'https://via.placeholder.com/150'); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                        <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                        <p><?php echo htmlspecialchars($product['category_name']); ?></p>
                        <p class="price">$<?php echo number_format($product['price'], 2); ?></p>
                        <?php if ($user): ?>
                            <form method="POST">
                                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                <input type="number" name="quantity" value="1" min="1" max="<?php echo $product['stock']; ?>">
                                <button type="submit" name="add_to_cart">Add to Cart</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Login Section -->
        <div id="login" class="section">
            <h2>Login</h2>
            <?php if (isset($error)): ?>
                <p style="color: red;"><?php echo $error; ?></p>
            <?php endif; ?>
            <form method="POST">
                <input type="email" name="email" placeholder="Email" required>
                <input type="password" name="password" placeholder="Password" required>
                <button type="submit" name="login">Login</button>
            </form>
        </div>

        <!-- Signup Section -->
        <div id="signup" class="section">
            <h2>Signup</h2>
            <form method="POST">
                <input type="text" name="username" placeholder="Username" required>
                <input type="email" name="email" placeholder="Email" required>
                <input type="password" name="password" placeholder="Password" required>
                <select name="role">
                    <option value="buyer">Buyer</option>
                    <option value="seller">Seller</option>
                </select>
                <button type="submit" name="signup">Signup</button>
            </form>
        </div>

        <!-- Profile Section -->
        <div id="profile" class="section">
            <h2>Profile</h2>
            <?php if ($user): ?>
                <p>Username: <?php echo htmlspecialchars($user['username']); ?></p>
                <p>Email: <?php echo htmlspecialchars($user['email']); ?></p>
                <p>Role: <?php echo htmlspecialchars($user['role']); ?></p>
            <?php endif; ?>
        </div>

        <!-- Manage Products Section -->
        <div id="manage_products" class="section">
            <h2>Manage Products</h2>
            <?php if ($user && $user['role'] == 'seller'): ?>
                <form method="POST">
                    <input type="text" name="name" placeholder="Product Name" required>
                    <textarea name="description" placeholder="Description" required></textarea>
                    <input type="number" name="price" placeholder="Price" step="0.01" required>
                    <input type="number" name="stock" placeholder="Stock" required>
                    <select name="category_id" required>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="image_url" placeholder="Image URL" required>
                    <button type="submit" name="add_product">Add Product</button>
                </form>
                <h3>Your Products</h3>
                <div class="product-grid">
                    <?php
                    $seller_products = $pdo->query("SELECT p.*, c.name as category_name FROM products p JOIN categories c ON p.category_id = c.id WHERE p.user_id = " . $user['id'])->fetchAll();
                    foreach ($seller_products as $product): ?>
                        <div class="product-card">
                            <img src="<?php echo htmlspecialchars($product['image_url'] ?: 'https://via.placeholder.com/150'); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                            <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                            <p><?php echo htmlspecialchars($product['category_name']); ?></p>
                            <p class="price">$<?php echo number_format($product['price'], 2); ?></p>
                            <a href="index.php?delete_product=<?php echo $product['id']; ?>" style="color: red;">Delete</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Cart Section -->
        <div id="cart" class="section">
            <h2>Cart</h2>
            <?php if ($cart_items): ?>
                <?php foreach ($cart_items as $item): ?>
                    <div class="cart-item">
                        <img src="<?php echo htmlspecialchars($item['image_url'] ?: 'https://via.placeholder.com/150'); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                        <div>
                            <h3><?php echo htmlspecialchars($item['name']); ?></h3>
                            <p>Price: $<?php echo number_format($item['price'], 2); ?></p>
                            <p>Quantity: <?php echo $item['quantity']; ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
                <form method="POST">
                    <button type="submit" name="checkout">Proceed to Checkout</button>
                </form>
            <?php else: ?>
                <p>Your cart is empty.</p>
            <?php endif; ?>
        </div>

        <!-- Order Confirmation Section -->
        <div id="order_confirmation" class="section">
            <h2>Order Confirmation</h2>
            <p>Thank you for your order! Your purchase is being processed.</p>
        </div>
    </div>

    <script>
        function showSection(sectionId) {
            document.querySelectorAll('.section').forEach(section => {
                section.classList.remove('active');
            });
            document.getElementById(sectionId).classList.add('active');
        }

        // Show initial section based on URL parameter
        const urlParams = new URLSearchParams(window.location.search);
        const section = urlParams.get('section') || 'home';
        showSection(section);
    </script>
</body>
</html>
