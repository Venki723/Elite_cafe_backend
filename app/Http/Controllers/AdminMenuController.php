<?php
 
namespace App\Http\Controllers;
 
use Illuminate\Http\Request;
use App\Models\MenuInfo; // Make sure this path is correct
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage; // <-- Add this for file operations
// use App\Models\Category; // <-- Only uncomment if showDashboard() remains here and you have this model
 
class AdminMenuController extends Controller
{
    // List all active menu items
    public function index()
{
    // $menuItems = MenuInfo::where('is_deleted', 0)
    // // ->orderBy('category')
    // // ->orderBy('name')
    // ->get();
$menuItems = MenuInfo::all();
 
 
    return response()->json([
        'data' => $menuItems
    ]);
}
 
 
 
    // Store a new menu item
    public function store(Request $request)
    {
       
       
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:menu_items,name', // Added unique validation
            'price' => 'required|numeric',
            'img' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'category' => 'nullable|string',
            'subcategory' => 'nullable|string',
            'veg' => 'required|boolean',
            'description' => 'nullable|string',
        ]);
 
        if ($request->hasFile('img')) {
            $image = $request->file('img');
            $imageName = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension(); // Added uniqid for more unique names
            $imagePath = $image->storeAs('menu_images', $imageName, 'public');
            $validated['img'] = 'storage/' . $imagePath; // Store with 'storage/' prefix
        }
 
        try {
            $menu = MenuInfo::create($validated);
            return response()->json($menu, 201);
        } catch (QueryException $e) {
            // Check for unique constraint violation (error code 1062 for MySQL)
            if ($e->errorInfo[1] == 1062) {
                return response()->json(['message' => 'An item with this name already exists.'], 409);
            }
            // Re-throw other query exceptions
            throw $e;
        }
    }
 
    // Update existing menu item
    public function update(Request $request, $id)
    {
        $menu = MenuInfo::findOrFail($id);
 
        // Define validation rules for update, making them sometimes optional
        $rules = [
            'name' => 'sometimes|required|string|max:255|unique:menu_items,name,'.$id, // 'sometimes' and unique with ignore
            'price' => 'sometimes|required|numeric',
            'img' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048', // Nullable here means it can be omitted or null
            'category' => 'sometimes|nullable|string',
            'subcategory' => 'sometimes|nullable|string',
            'veg' => 'sometimes|required|boolean',
            'description' => 'sometimes|nullable|string',
        ];
 
        $validated = $request->validate($rules);
 
        // Handle image update
        if ($request->hasFile('img')) {
            // Delete old image from storage if it exists and is a 'storage/' path
            if ($menu->img && str_starts_with($menu->img, 'storage/')) {
                // Get the path relative to the 'public' disk root (remove 'storage/' prefix)
                $oldImagePath = str_replace('storage/', '', $menu->img);
                Storage::disk('public')->delete($oldImagePath);
            }
 
            $image = $request->file('img');
            $imageName = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
            $imagePath = $image->storeAs('menu_images', $imageName, 'public');
            $validated['img'] = 'storage/' . $imagePath; // Store with 'storage/' prefix
        }
 
        // Fill the model with validated data (including image path if updated)
        $menu->fill($validated);
        $menu->save();
 
        return response()->json([
            'message' => 'Menu item updated successfully',
            'data' => $menu
        ]);
    }
 
    // Show hidden items
    // public function hiddenItems()
    // {
    //     $hiddenItems = MenuInfo::where('is_deleted', 1)->get();
    //     return response()->json(['data' => $hiddenItems]);
    // }
 
    // Toggle visibility (hide/restore)
    public function toggleVisibility(Request $request, $id)
{
    $request->validate([
        'is_deleted' => 'required|boolean',
    ]);
 
    $menu = MenuInfo::findOrFail($id);
    $menu->is_deleted = $request->input('is_deleted');
    $menu->save();
 
    return response()->json([
        'message' => $menu->is_deleted ? 'Item marked as Inactive' : 'Item marked as Active',
        'data' => $menu
    ]);
}
 
 
   
 
    // Removed the redundant destroy($id) method as toggleVisibility handles hiding.
    // If you need a permanent delete, implement it separately with proper authorization.
 
    // If this controller is solely for API, this method should be removed or moved
    // to a dedicated frontend controller (like AdminFrontendController).
    /*
    public function showDashboard()
    {
        // Ensure you have `use App\Models\Category;` at the top if you keep this.
        $categories = Category::with('subcategories')->get();
        $menuItems = MenuInfo::all(); // Consider if you want all or just active
 
        return view('admin_dashboard', compact('categories', 'menuItems'));
    }
    */
}
 