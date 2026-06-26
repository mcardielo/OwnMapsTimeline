<div class="flex items-center justify-center min-h-screen bg-gray-100 px-4">
    <div class="bg-white rounded-lg shadow-md p-8 w-full max-w-sm">
        <h1 class="text-2xl font-bold text-center mb-6">🗺️ OwnTracks</h1>
        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-3 mb-4 text-sm"><?= View::esc($error) ?></div>
        <?php endif; ?>
        <form method="POST" action="/login">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                <input type="text" name="username" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" name="password" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <button type="submit"
                class="w-full bg-blue-600 text-white py-2 rounded-md hover:bg-blue-700 transition">
                Sign In
            </button>
        </form>
    </div>
</div>
