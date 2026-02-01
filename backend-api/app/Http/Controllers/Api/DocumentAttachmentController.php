<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;


class DocumentAttachmentController extends Controller
{
    public function index(Request $request)
    {
        $attachments = DocumentAttachment::with('uploader')
            ->when($request->documenttype, fn($q) => $q->where('documenttype', $request->documenttype))
            ->when($request->referenceid, fn($q) => $q->where('referenceid', $request->referenceid))
            ->orderBy('uploaddate', 'desc')
            ->paginate(20);

        return response()->json($attachments);
    }

    public function store(Request $request)
    {
        $request->validate([
            'documenttype' => ['required', Rule::in(DocumentAttachment::DOCUMENT_TYPES)],
            'referenceid' => 'required|integer|exists:'.match($request->documenttype){
                'quotation' => 'quotations,quotationid',
                'po' => 'purchaseorders,poid',
                'invoice' => 'invoices,invoiceid',
                'deliverynote' => 'deliverynotes,deliverynoteid',
                'payment' => 'payments,paymentid',
                default => '0'
            },
            'file' => 'required|file|max:10240|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', // Max 10MB
        ]);

        $file = $request->file('file');
        $documentType = $request->documenttype;
        $referenceId = $request->referenceid;
        $userId = Auth::id();

        $filename = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $safeName = Str::slug(pathinfo($filename, PATHINFO_FILENAME)) . '_' . time() . '.' . $extension;
        $path = "documents/{$documentType}s/{$safeName}";

        $diskPath = $file->storeAs('public/documents/' . $documentType . 's', $safeName);

        $attachment = DocumentAttachment::create([
            'documenttype' => $documentType,
            'referenceid' => $referenceId,
            'filename' => $filename,
            'filepath' => $diskPath,
            'filesize' => $file->getSize(),
            'filetype' => $file->getMimeType(),
            'uploadedby' => $userId,
        ]);

        return response()->json($attachment->load('uploader'), 201);
    }

    public function show(DocumentAttachment $documentAttachment)
    {
        return response()->json($documentAttachment->load('uploader'));
    }

    public function destroy(DocumentAttachment $documentAttachment)
    {
        Storage::disk('public')->delete($documentAttachment->filepath);
        $documentAttachment->delete();

        return response()->json(['message' => 'Attachment deleted']);
    }

  public function download(DocumentAttachment $documentAttachment)
{
    if (!Storage::exists($documentAttachment->filepath)) {
        abort(404, 'File not found');
    }

    return Storage::download(
        $documentAttachment->filepath,
        $documentAttachment->filename
    );
}

}
