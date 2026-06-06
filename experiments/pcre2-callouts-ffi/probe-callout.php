<?php
/**
 * WORKING probe: PCRE2 callouts via PHP FFI.
 *
 * Refutes exp-pcre-ffi.php / commit dea9df7. The correct idiom is to pass
 * the PHP closure DIRECTLY as the function-pointer argument to the FFI'd C
 * function. PHP FFI builds a real C trampoline via libffi ffi_prep_closure_loc.
 */

if ( ! extension_loaded( 'ffi' ) ) {
	fwrite( STDERR, "FFI extension not loaded\n" );
	exit( 1 );
}

$cdef = <<<'CDEF'
typedef unsigned char  PCRE2_UCHAR8;
typedef const PCRE2_UCHAR8 *PCRE2_SPTR8;
typedef size_t PCRE2_SIZE;

typedef struct pcre2_real_compile_context_8 pcre2_compile_context_8;
typedef struct pcre2_real_match_context_8   pcre2_match_context_8;
typedef struct pcre2_real_general_context_8 pcre2_general_context_8;
typedef struct pcre2_real_code_8            pcre2_code_8;
typedef struct pcre2_real_match_data_8      pcre2_match_data_8;

typedef struct pcre2_callout_block_8 {
    uint32_t      version;
    uint32_t      callout_number;
    uint32_t      capture_top;
    uint32_t      capture_last;
    PCRE2_SIZE   *offset_vector;
    PCRE2_SPTR8   mark;
    PCRE2_SPTR8   subject;
    PCRE2_SIZE    subject_length;
    PCRE2_SIZE    start_match;
    PCRE2_SIZE    current_position;
    PCRE2_SIZE    pattern_position;
    PCRE2_SIZE    next_item_length;
    PCRE2_SIZE    callout_string_offset;
    PCRE2_SIZE    callout_string_length;
    PCRE2_SPTR8   callout_string;
    uint32_t      callout_flags;
} pcre2_callout_block_8;

pcre2_code_8 *pcre2_compile_8(PCRE2_SPTR8 pattern, PCRE2_SIZE length,
    uint32_t options, int *errorcode, PCRE2_SIZE *erroroffset,
    pcre2_compile_context_8 *ccontext);
void pcre2_code_free_8(pcre2_code_8 *code);

pcre2_match_data_8 *pcre2_match_data_create_from_pattern_8(
    const pcre2_code_8 *code, pcre2_general_context_8 *gcontext);
void pcre2_match_data_free_8(pcre2_match_data_8 *match_data);

pcre2_match_context_8 *pcre2_match_context_create_8(pcre2_general_context_8 *gcontext);
void pcre2_match_context_free_8(pcre2_match_context_8 *mcontext);

int pcre2_set_callout_8(pcre2_match_context_8 *mcontext,
    int (*callout_function)(pcre2_callout_block_8 *, void *),
    void *callout_data);

int pcre2_match_8(const pcre2_code_8 *code, PCRE2_SPTR8 subject,
    PCRE2_SIZE length, PCRE2_SIZE startoffset, uint32_t options,
    pcre2_match_data_8 *match_data, pcre2_match_context_8 *mcontext);

void pcre2_get_error_message_8(int errorcode, PCRE2_UCHAR8 *buffer, PCRE2_SIZE bufflen);
CDEF;

$ffi = FFI::cdef( $cdef, '/opt/homebrew/lib/libpcre2-8.dylib' );

/**
 * Right-recursive arithmetic grammar with a numbered callout at every
 * alternative entry. (?C1) sum, (?C2) product, (?C3) atom, (?C4) paren.
 *
 *   sum     = product ('+' sum)?
 *   product = atom ('*' product)?
 *   atom    = number | '(' sum ')'
 */
$pattern = '(?(DEFINE)'
	. '(?<sum>(?C1)(?&product)(?:\+(?&sum))?)'
	. '(?<product>(?C2)(?&atom)(?:\*(?&product))?)'
	. '(?<atom>(?C3)\d+|(?C4)\((?&sum)\))'
	. ')^(?&sum)$';

$err_code = $ffi->new( 'int' );
$err_off  = $ffi->new( 'size_t' );
$pat_arr  = $ffi->new( 'char[' . strlen( $pattern ) . ']', false );
FFI::memcpy( $pat_arr, $pattern, strlen( $pattern ) );

$code = $ffi->pcre2_compile_8(
	$ffi->cast( 'PCRE2_SPTR8', FFI::addr( $pat_arr ) ),
	strlen( $pattern ),
	0,
	FFI::addr( $err_code ),
	FFI::addr( $err_off ),
	null
);
if ( null === $code ) {
	$buf = $ffi->new( 'char[256]' );
	$ffi->pcre2_get_error_message_8( $err_code->cdata, $ffi->cast( 'PCRE2_UCHAR8 *', FFI::addr( $buf ) ), 256 );
	fwrite( STDERR, 'compile failed: code=' . $err_code->cdata . ' offset=' . $err_off->cdata . ' msg=' . FFI::string( FFI::addr( $buf ) ) . "\n" );
	exit( 1 );
}
echo "Pattern compiled OK\n";

$mctx = $ffi->pcre2_match_context_create_8( null );

$trace   = array();
$callout = function ( $blockptr, $data ) use ( &$trace ) {
	$blk     = $blockptr[0]; // deref pcre2_callout_block_8*
	$trace[] = array(
		'num' => $blk->callout_number,
		'pos' => $blk->pattern_position,
		'cur' => $blk->current_position,
		'cap' => $blk->capture_last,
	);
	return 0; // continue matching
};

// THE CORRECT IDIOM: pass the closure DIRECTLY as the function pointer.
$rc = $ffi->pcre2_set_callout_8( $mctx, $callout, null );
echo "pcre2_set_callout_8 rc=$rc\n";

$subject = '1+2*3';
$mdata   = $ffi->pcre2_match_data_create_from_pattern_8( $code, null );
$subj    = $ffi->new( 'char[' . strlen( $subject ) . ']', false );
FFI::memcpy( $subj, $subject, strlen( $subject ) );

$rc = $ffi->pcre2_match_8(
	$code,
	$ffi->cast( 'PCRE2_SPTR8', FFI::addr( $subj ) ),
	strlen( $subject ),
	0,
	0,
	$mdata,
	$mctx
);

echo "match rc=$rc (>=0 means matched)\n";
echo 'callout fired ' . count( $trace ) . " times\n";
$rulemap = array( 1 => 'sum', 2 => 'product', 3 => 'atom(num)', 4 => 'atom(paren)' );
foreach ( $trace as $i => $t ) {
	printf(
		"  [%2d] C%d %-12s subject_pos=%d\n",
		$i,
		$t['num'],
		$rulemap[ $t['num'] ] ?? '?',
		$t['cur']
	);
}
