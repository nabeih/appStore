const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const {gunzipSync} = require('node:zlib');

async function main() {
    const root = path.resolve(__dirname, '../../..');
    const destination = path.join(root, 'public/assets/vendor/search');
    const versions = {};
    for (const [name, expected] of Object.entries({'@zxing/browser':'0.2.1', 'tesseract.js':'7.0.0', '@tesseract.js-data/eng':'1.0.0'})) {
        const installed = JSON.parse(await fs.readFile(path.join(root, 'node_modules', name, 'package.json'), 'utf8'));
        if (installed.version !== expected) throw new Error('Review the asset mapping before changing '+name+' version.');
        versions[name] = installed.version;
    }
    versions['tesseract.js-core'] = JSON.parse(await fs.readFile(path.join(root,'node_modules/tesseract.js-core/package.json'),'utf8')).version;
    const files = [
        ['@zxing/browser/umd/zxing-browser.min.js', 'zxing-browser.min.js'],
        ['tesseract.js/dist/tesseract.min.js', 'ocr/tesseract.min.js'],
        ['tesseract.js/dist/worker.min.js', 'ocr/worker.min.js'],
        ['tesseract.js/dist/tesseract.min.js.LICENSE.txt', 'licenses/tesseract-bundle.txt'],
        ['tesseract.js/dist/worker.min.js.LICENSE.txt', 'licenses/tesseract-worker.txt'],
        ['tesseract.js-core/tesseract-core-lstm.wasm.js', 'ocr/core/tesseract-core-lstm.wasm.js'],
        ['tesseract.js-core/tesseract-core-simd-lstm.wasm.js', 'ocr/core/tesseract-core-simd-lstm.wasm.js'],
        ['tesseract.js-core/tesseract-core-relaxedsimd-lstm.wasm.js', 'ocr/core/tesseract-core-relaxedsimd-lstm.wasm.js'],
        ['@tesseract.js-data/eng/4.0.0_best_int/eng.traineddata.gz', 'ocr/lang/eng.traineddata'],
    ];
    for (const name of ['@zxing/browser','@zxing/library','tesseract.js','tesseract.js-core','@tesseract.js-data/eng']) {
        const folder = path.join(root, 'node_modules', name);
        for (const file of await fs.readdir(folder)) {
            if (/^license/i.test(file)) files.push([name+'/'+file, 'licenses/'+name.replaceAll('/','-').replace('@','')+'-'+file]);
        }
    }
    const manifest = {versions, source:'Pinned npm packages from registry.npmjs.org; install scripts disabled', files:[]};
    for (const [source, target] of files) {
        const original = await fs.readFile(path.join(root, 'node_modules', source));
        const bytes = source.endsWith('.gz') && !target.endsWith('.gz') ? gunzipSync(original) : original;
        if (bytes.length === 0) throw new Error('Package installation is incomplete: '+source);
        const output = path.join(destination, target);
        await fs.mkdir(path.dirname(output), {recursive:true});
        await fs.writeFile(output, bytes);
        manifest.files.push({path:target, bytes:bytes.length, sha256:crypto.createHash('sha256').update(bytes).digest('hex')});
    }
    await fs.writeFile(path.join(destination,'manifest.json'), JSON.stringify(manifest,null,2)+'\n');
    console.log(JSON.stringify({versions, files:manifest.files.length, total_bytes:manifest.files.reduce((sum,file)=>sum+file.bytes,0)}));
}
main().catch(error=>{console.error(error.message);process.exitCode=1;});
