<div class="max-w-4xl mx-auto px-4 py-8">
    <h2 class="text-2xl font-bold mb-2">👥 Users</h2>
    <p class="text-gray-500 text-sm mb-6">Manage user accounts</p>

    <?php if ($error): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-3 mb-4 text-sm rounded"><?= View::esc($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-3 mb-4 text-sm rounded"><?= View::esc($success) ?></div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="text-left px-4 py-2 font-medium text-gray-600">Username</th>
                    <th class="text-left px-4 py-2 font-medium text-gray-600">Role</th>
                    <th class="text-left px-4 py-2 font-medium text-gray-600">Created</th>
                    <th class="text-right px-4 py-2 font-medium text-gray-600">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr class="border-b border-gray-100 last:border-0">
                    <td class="px-4 py-2 font-medium"><?= View::esc($u['username']) ?></td>
                    <td class="px-4 py-2">
                        <span class="text-xs px-2 py-0.5 rounded-full <?= $u['role'] === 'admin' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600' ?>">
                            <?= View::esc($u['role']) ?>
                        </span>
                    </td>
                    <td class="px-4 py-2 text-gray-500"><?= View::esc($u['created_at']) ?></td>
                    <td class="px-4 py-2 text-right">
                        <?php if ($u['username'] !== $currentUser): ?>
                        <form method="POST" action="/users/delete" class="inline"
                            onsubmit="return confirm('Delete user <?= View::esc($u['username']) ?>?')">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button class="text-xs text-red-600 hover:underline">Delete</button>
                        </form>
                        <?php else: ?>
                            <span class="text-xs text-gray-400">(you)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="text-xs text-gray-400 mt-2">
        Note: In local auth mode, new users can only be created by the admin.
        In Authelia mode, users are auto-created on first visit.
    </p>

    <div class="mt-8">
        <a href="/dashboard" class="text-blue-600 hover:underline text-sm">← Back to Dashboard</a>
    </div>
</div>
